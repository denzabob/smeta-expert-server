<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Models\Expert\ExpertMaterialIdentity;
use App\Models\Expert\ExpertProjectMaterial;
use Illuminate\Support\Facades\Log;

final class ExpertMaterialIdentityService
{
    public function __construct(
        private readonly ExpertMaterialIdentityRepository $repository,
        private readonly ExpertMaterialIdentityTextBuilder $textBuilder,
        private readonly ExpertPdfOcrCache $ocrCache,
        private readonly ExpertStorageService $storage,
    ) {}

    public function get(ExpertProjectMaterial $material): ExpertMaterialIdentity
    {
        return $this->ensureBasic($material);
    }

    /** Candidate discovery uses only metadata and previously stored identity. */
    public function forCandidate(ExpertProjectMaterial $material): ExpertMaterialIdentity
    {
        return $this->ensureBasic($material, metadataOnly: true);
    }

    public function ensureBasic(ExpertProjectMaterial $material, ?string $knownSourceSha256 = null, bool $metadataOnly = false): ExpertMaterialIdentity
    {
        $existing = $this->repository->findByMaterial($material);
        $version = (string) config('expert.context.identity.version', 'v1');
        $metadataHash = $this->metadataHash($material);
        $sourceSha256 = $knownSourceSha256 ?? ($metadataOnly ? $existing?->source_sha256 : $this->sourceSha256($material));
        $sourceChanged = ! $metadataOnly && $existing !== null && $existing->source_sha256 !== $sourceSha256;
        $versionChanged = $existing !== null && $existing->schema_version !== $version;
        $metadataChanged = $existing !== null && $existing->metadata_hash !== $metadataHash;
        if ($existing !== null && ! $sourceChanged && ! $versionChanged && ! $metadataChanged) {
            return $existing;
        }

        $descriptor = $this->basicDescriptor($material);
        $stale = $sourceChanged || $versionChanged;
        $values = [
            'schema_version' => $version,
            'state' => $stale ? 'stale' : ($existing?->state ?? 'basic'),
            'source_sha256' => $sourceSha256,
            'metadata_hash' => $metadataHash,
            'descriptor' => $descriptor,
            'content_source' => $stale ? 'filename' : ($existing?->content_source ?? 'filename'),
            'routing_text' => $stale ? null : $existing?->routing_text,
            'content_fingerprint' => $stale ? null : $existing?->content_fingerprint,
            'confidence' => $stale ? 0.0 : ($existing?->confidence ?? 0.0),
            'last_error_code' => $stale ? null : $existing?->last_error_code,
            'built_at' => now(),
        ];
        $identity = $this->repository->save($material, $values);
        $this->log($existing === null ? 'created' : 'rebuilt', $material, $identity, $sourceChanged, $metadataChanged);

        return $identity;
    }

    public function enrichFromText(
        ExpertProjectMaterial $material,
        string $text,
        string $source,
        string $sourceFingerprint,
        ?string $knownSourceSha256 = null,
    ): ExpertMaterialIdentity {
        $identity = $this->ensureBasic($material, $knownSourceSha256);
        if ($identity->source_sha256 === null) {
            return $identity;
        }
        $routingText = $this->textBuilder->build($text);
        if ($routingText === '') {
            return $identity;
        }

        $priority = ['filename' => 0, 'local_text' => 1, 'provider_pdf_text' => 2, 'provider_pdf_ocr' => 2];
        if ($identity->state === 'content_enriched'
            && ($priority[$source] ?? 1) < ($priority[$identity->content_source] ?? 0)) {
            return $identity;
        }
        $fingerprint = hash('sha256', implode('|', [
            $identity->source_sha256 ?? '', $source, $sourceFingerprint, (string) config('expert.context.identity.version', 'v1'),
        ]));
        if ($identity->state === 'content_enriched' && $identity->content_fingerprint === $fingerprint
            && $identity->routing_text === $routingText) {
            return $identity;
        }
        $wasEnriched = $identity->state === 'content_enriched';
        $identity = $this->repository->save($material, [
            'schema_version' => (string) config('expert.context.identity.version', 'v1'),
            'state' => 'content_enriched',
            'source_sha256' => $identity->source_sha256,
            'metadata_hash' => $identity->metadata_hash,
            'descriptor' => $identity->descriptor,
            'routing_text' => $routingText,
            'content_source' => $source,
            'content_fingerprint' => $fingerprint,
            'confidence' => 1.0,
            'last_error_code' => null,
            'built_at' => now(),
        ]);
        $this->log($wasEnriched ? 'rebuilt' : 'enriched', $material, $identity, false, false);

        return $identity;
    }

    public function markStale(ExpertProjectMaterial $material): void
    {
        $this->repository->markStale($material);
    }

    public function enrichPdfCandidate(ExpertPdfOcrCandidate $candidate, string $text): void
    {
        try {
            $material = ExpertProjectMaterial::query()
                ->where('public_id', $candidate->materialPublicId)
                ->whereHas('project', fn ($query) => $query->where('public_id', $candidate->projectPublicId))
                ->first();
            if ($material === null || $this->sourceSha256($material) !== strtolower($candidate->sha256)) {
                return;
            }
            $sourceFingerprint = implode('|', [
                $candidate->processingIntent->value,
                $this->ocrCache->processingEngine($candidate->processingIntent),
                (string) config('expert.pdf_ocr.cache_version', 'v1'),
            ]);
            $this->bestEffortEnrich($material, $text, $candidate->processingStrategy(), $sourceFingerprint, $candidate->sha256);
        } catch (\Throwable $exception) {
            Log::warning('Expert material identity failed.', [
                'material_public_id' => $candidate->materialPublicId,
                'identity_state' => 'failed',
                'schema_version' => (string) config('expert.context.identity.version', 'v1'),
                'content_source' => $candidate->processingStrategy(),
                'routing_text_chars' => 0,
                'source_changed' => false,
                'metadata_changed' => false,
                'error_code' => 'identity_enrichment_failed',
                'exception' => $exception::class,
            ]);
        }
    }

    public function bestEffortEnrich(
        ExpertProjectMaterial $material,
        string $text,
        string $source,
        string $sourceFingerprint,
        ?string $knownSourceSha256 = null,
    ): void {
        try {
            $this->enrichFromText($material, $text, $source, $sourceFingerprint, $knownSourceSha256);
        } catch (\Throwable $exception) {
            $existing = null;
            try {
                $existing = $this->repository->findByMaterial($material);
                if ($existing === null || $existing->state !== 'content_enriched') {
                    $this->ensureBasic($material, $knownSourceSha256);
                    $this->repository->save($material, ['state' => 'failed', 'last_error_code' => 'identity_enrichment_failed']);
                }
            } catch (\Throwable) {
                // Identity persistence is optional for the chat request.
            }
            Log::warning('Expert material identity failed.', [
                'material_public_id' => (string) $material->public_id,
                'identity_state' => $existing?->state ?? 'failed',
                'schema_version' => (string) config('expert.context.identity.version', 'v1'),
                'content_source' => $source,
                'routing_text_chars' => 0,
                'source_changed' => false,
                'metadata_changed' => false,
                'error_code' => 'identity_enrichment_failed',
                'exception' => $exception::class,
            ]);
        }
    }

    private function metadataHash(ExpertProjectMaterial $material): string
    {
        $metadata = is_array($material->metadata) ? $material->metadata : [];

        return hash('sha256', json_encode([
            (string) $material->original_name, (string) $material->mime_type,
            (string) $material->extension, (string) $material->category,
            is_string($metadata['label'] ?? null) ? $metadata['label'] : null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** @return array{display_name: string, aliases: list<string>} */
    private function basicDescriptor(ExpertProjectMaterial $material): array
    {
        $name = ExpertMaterialPresentationName::resolve($material);
        $stem = pathinfo($name, PATHINFO_FILENAME);
        $metadata = is_array($material->metadata) ? $material->metadata : [];
        $label = is_string($metadata['label'] ?? null) ? trim($metadata['label']) : '';

        return ['display_name' => $label !== '' ? $label : $name,
            'aliases' => array_values(array_unique(array_filter([$name, $stem, $label], static fn (string $value): bool => $value !== ''))),
        ];
    }

    private function sourceSha256(ExpertProjectMaterial $material): ?string
    {
        $key = $this->storage->keyForMaterial($material);
        if ($key === '') {
            return null;
        }
        try {
            $disk = $this->storage->diskForMaterial($material);
            $context = $this->storage->contextForMaterial($material);
            if (! $this->storage->exists($disk, $key, $context)) {
                return null;
            }

            return $this->storage->sha256($disk, $key, $context);
        } catch (\Throwable) {
            return null;
        }
    }

    private function log(string $event, ExpertProjectMaterial $material, ExpertMaterialIdentity $identity, bool $sourceChanged, bool $metadataChanged): void
    {
        Log::info('Expert material identity '.$event.'.', [
            'material_public_id' => (string) $material->public_id,
            'identity_state' => (string) $identity->state,
            'schema_version' => (string) $identity->schema_version,
            'content_source' => (string) $identity->content_source,
            'routing_text_chars' => mb_strlen((string) $identity->routing_text, 'UTF-8'),
            'source_changed' => $sourceChanged,
            'metadata_changed' => $metadataChanged,
        ]);
    }
}
