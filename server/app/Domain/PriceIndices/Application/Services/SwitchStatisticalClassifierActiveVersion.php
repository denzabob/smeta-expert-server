<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Application\Data\ClassifierVersionActivationResult;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifier;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierActiveVersion;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierImport;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierSourceFile;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierVersion;
use App\Domain\PriceIndices\Domain\Enums\ClassifierImportStatus;
use App\Domain\PriceIndices\Domain\Enums\ClassifierPointerSwitchMode;
use App\Domain\PriceIndices\Domain\Enums\ClassifierSourceTrustTier;
use App\Domain\PriceIndices\Domain\Enums\ClassifierVersionStatus;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierVersionActivationException;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class SwitchStatisticalClassifierActiveVersion
{
    public function switchTo(
        string $targetVersionPublicId,
        CarbonInterface $asOfDate,
        CarbonInterface $activatedAt,
        ClassifierPointerSwitchMode $mode,
        ?int $activatedBy = null,
        ?string $reason = null,
    ): ClassifierVersionActivationResult {
        return DB::transaction(function () use (
            $activatedAt,
            $activatedBy,
            $asOfDate,
            $mode,
            $reason,
            $targetVersionPublicId,
        ): ClassifierVersionActivationResult {
            $unlockedTarget = StatisticalClassifierVersion::query()
                ->where('public_id', $targetVersionPublicId)
                ->first();

            if ($unlockedTarget === null) {
                $this->fail('classifier_version_not_found', 'The requested classifier version was not found.');
            }

            $classifier = StatisticalClassifier::query()
                ->whereKey($unlockedTarget->classifier_id)
                ->lockForUpdate()
                ->first();

            if ($classifier === null) {
                $this->fail('classifier_not_found', 'The classifier for the requested version was not found.');
            }

            $target = StatisticalClassifierVersion::query()
                ->whereKey($unlockedTarget->id)
                ->where('classifier_id', $classifier->id)
                ->lockForUpdate()
                ->first();

            if ($target === null) {
                $this->fail('classifier_version_not_found', 'The requested classifier version was not found.');
            }

            $pointer = StatisticalClassifierActiveVersion::query()
                ->where('classifier_id', $classifier->id)
                ->lockForUpdate()
                ->first();

            if ($mode === ClassifierPointerSwitchMode::Rollback && $pointer === null) {
                $this->fail('active_version_not_set', 'The classifier has no active version to roll back.');
            }

            $this->assertTargetIsActivatable($classifier, $target, $asOfDate, $mode);
            $previous = $pointer === null
                ? null
                : StatisticalClassifierVersion::query()->whereKey($pointer->classifier_version_id)->first();

            if ($pointer !== null && (int) $pointer->classifier_version_id === (int) $target->id) {
                return $this->result($classifier, $target, $previous, 'already_active');
            }

            $attributes = [
                'classifier_version_id' => $target->id,
                'activated_at' => $activatedAt,
                'activated_by' => $activatedBy,
                'activation_reason' => $reason,
            ];

            if ($pointer === null) {
                $pointer = new StatisticalClassifierActiveVersion([
                    'classifier_id' => $classifier->id,
                    ...$attributes,
                ]);
            } else {
                $pointer->fill($attributes);
            }

            $pointer->save();

            return $this->result($classifier, $target, $previous, 'activated');
        }, 3);
    }

    private function assertTargetIsActivatable(
        StatisticalClassifier $classifier,
        StatisticalClassifierVersion $version,
        CarbonInterface $asOfDate,
        ClassifierPointerSwitchMode $mode,
    ): void {
        $allowedStatuses = $mode === ClassifierPointerSwitchMode::Activation
            ? [ClassifierVersionStatus::Ready]
            : [ClassifierVersionStatus::Ready, ClassifierVersionStatus::Superseded];

        if (! in_array($version->status, $allowedStatuses, true)) {
            $this->fail(
                'classifier_version_not_ready',
                'The requested classifier version is not eligible for this pointer switch.',
            );
        }

        if ($version->effective_from === null
            || $version->effective_from->startOfDay()->gt($asOfDate->toImmutable()->startOfDay())
        ) {
            $this->fail(
                'classifier_version_future_effective',
                'A future-effective classifier version cannot become active.',
            );
        }

        $import = StatisticalClassifierImport::query()
            ->whereKey($version->classifier_import_id)
            ->first();

        if (! $import instanceof StatisticalClassifierImport
            || $import->classifier_id !== $classifier->id
            || $import->status !== ClassifierImportStatus::Ready
            || $import->nodes_parsed !== $version->node_count
            || $import->validation_errors_count !== 0
        ) {
            $this->provenanceFailure();
        }

        $source = StatisticalClassifierSourceFile::query()
            ->whereKey($import->source_file_id)
            ->first();

        if (! $source instanceof StatisticalClassifierSourceFile
            || $source->classifier_id !== $classifier->id
            || $source->trust_tier !== ClassifierSourceTrustTier::OfficialAuthoritative
        ) {
            $this->provenanceFailure();
        }

        $this->assertImportProvenance($version, $import, $source);
        $this->assertPersistedTree($version);
    }

    private function assertImportProvenance(
        StatisticalClassifierVersion $version,
        StatisticalClassifierImport $import,
        StatisticalClassifierSourceFile $source,
    ): void {
        $summary = $import->validation_summary_json;
        $fingerprint = is_array($summary) ? ($summary['candidate_fingerprint'] ?? null) : null;

        if (! is_array($summary)
            || ! is_string($summary['candidate_key'] ?? null)
            || ($summary['candidate_key'] ?? '') === ''
            || ! is_string($fingerprint)
            || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1
            || ($summary['version_label'] ?? null) !== $version->version_label
            || ($summary['effective_from'] ?? null) !== $version->effective_from?->toDateString()
            || ($summary['source']['sha256'] ?? null) !== $source->sha256
            || ($summary['parser']['code'] ?? null) !== $import->parser_code
            || (string) ($summary['parser']['version'] ?? '') !== (string) $import->parser_version
            || (int) ($summary['metrics']['total_nodes_count'] ?? 0) !== $version->node_count
        ) {
            $this->provenanceFailure();
        }
    }

    private function assertPersistedTree(StatisticalClassifierVersion $version): void
    {
        if ($version->node_count === null || $version->node_count <= 0) {
            $this->integrityFailure();
        }

        $aggregate = DB::table('statistical_classifier_nodes')
            ->where('classifier_version_id', $version->id)
            ->selectRaw('COUNT(*) AS total_count')
            ->selectRaw('COUNT(DISTINCT code) AS distinct_code_count')
            ->selectRaw('SUM(CASE WHEN parent_node_id IS NULL THEN 1 ELSE 0 END) AS root_count')
            ->selectRaw('SUM(CASE WHEN source_order IS NULL THEN 1 ELSE 0 END) AS null_order_count')
            ->selectRaw('COUNT(DISTINCT source_order) AS distinct_order_count')
            ->selectRaw('MIN(source_order) AS min_order')
            ->selectRaw('MAX(source_order) AS max_order')
            ->first();

        if ($aggregate === null
            || (int) $aggregate->total_count !== $version->node_count
            || (int) $aggregate->distinct_code_count !== $version->node_count
            || (int) $aggregate->root_count <= 0
            || (int) $aggregate->null_order_count !== 0
            || (int) $aggregate->distinct_order_count !== $version->node_count
            || (int) $aggregate->min_order !== 1
            || (int) $aggregate->max_order !== $version->node_count
        ) {
            $this->integrityFailure();
        }

        $reachable = DB::selectOne(
            <<<'SQL'
                WITH RECURSIVE classifier_tree AS (
                    SELECT id
                    FROM statistical_classifier_nodes
                    WHERE classifier_version_id = ? AND parent_node_id IS NULL
                    UNION ALL
                    SELECT child.id
                    FROM statistical_classifier_nodes AS child
                    INNER JOIN classifier_tree AS parent ON parent.id = child.parent_node_id
                    WHERE child.classifier_version_id = ?
                )
                SELECT COUNT(*) AS reachable_count
                FROM classifier_tree
                SQL,
            [$version->id, $version->id],
        );

        if ($reachable === null || (int) $reachable->reachable_count !== $version->node_count) {
            $this->integrityFailure();
        }
    }

    private function result(
        StatisticalClassifier $classifier,
        StatisticalClassifierVersion $target,
        ?StatisticalClassifierVersion $previous,
        string $status,
    ): ClassifierVersionActivationResult {
        return new ClassifierVersionActivationResult(
            classifierCode: $classifier->code,
            classifierPublicId: $classifier->public_id,
            targetVersionPublicId: $target->public_id,
            targetVersionLabel: $target->version_label,
            effectiveFrom: $target->effective_from->toDateString(),
            nodeCount: $target->node_count,
            previousVersionPublicId: $previous?->public_id,
            previousVersionLabel: $previous?->version_label,
            status: $status,
        );
    }

    private function provenanceFailure(): never
    {
        $this->fail(
            'classifier_version_provenance_invalid',
            'The requested classifier version does not have complete trusted provenance.',
        );
    }

    private function integrityFailure(): never
    {
        $this->fail(
            'classifier_version_integrity_failure',
            'The persisted classifier tree failed activation integrity validation.',
        );
    }

    private function fail(string $errorCode, string $safeMessage): never
    {
        throw new ClassifierVersionActivationException($errorCode, $safeMessage);
    }
}
