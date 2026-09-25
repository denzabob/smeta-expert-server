<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Models\Expert\ExpertProject;
use App\Models\Expert\ExpertProjectMaterial;
use App\Services\LLM\Enums\LLMFileProcessingIntent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

final class ExpertMaterialContextBuilder
{
    public function __construct(
        private readonly ExpertMaterialTextExtractorInterface $extractor,
        private readonly ExpertMaterialProcessingLimits $limits,
        private readonly ExpertMaterialIdentityService $identities,
    ) {}

    /**
     * @param  list<string>  $publicIds
     * @return list<array{public_id: string, name: string, mime_type: string, text: string}>
     *
     * @throws ExpertMaterialContextException
     */
    public function build(ExpertProject $project, array $publicIds): array
    {
        if ($publicIds === []) {
            return [];
        }

        $requestedIds = array_values(array_unique($publicIds));
        $materialsByPublicId = $project->materials()
            ->whereIn('public_id', $requestedIds)
            ->get()
            ->keyBy('public_id');

        if ($materialsByPublicId->count() !== count($requestedIds)) {
            throw ExpertMaterialContextException::notFound();
        }

        $disk = Storage::disk('local');
        $maxTotalBytes = max(1, (int) config('expert.material_context.max_total_material_bytes', 10 * 1024 * 1024));
        $totalNonPdfBytes = 0;

        /** @var list<array{material: ExpertProjectMaterial, bytes: int}> $resolvedMaterials */
        $resolvedMaterials = [];
        foreach ($requestedIds as $publicId) {
            /** @var ExpertProjectMaterial $material */
            $material = $materialsByPublicId->get($publicId);
            $bytes = null;

            try {
                if ($this->xlsxContextIsDisabled($material)) {
                    throw ExpertMaterialContextException::temporarilyDisabled();
                }

                if (! $this->extractor->supports($material)) {
                    throw ExpertMaterialContextException::unsupported();
                }

                if (! $disk->exists($material->storage_path)) {
                    throw ExpertMaterialContextException::extractionFailed();
                }

                $bytes = (int) $disk->size($material->storage_path);
            } catch (ExpertMaterialContextException $exception) {
                $this->logMaterialContextFailure($material, $bytes, $exception);
                throw $exception;
            } catch (\Throwable $exception) {
                $failure = ExpertMaterialContextException::extractionFailed();
                $this->logMaterialContextFailure($material, $bytes, $failure, $exception);
                throw $failure;
            }

            $limits = $this->limits->resolve($material);
            if ($bytes > $limits['max_source_bytes']) {
                throw $limits['kind'] === 'pdf'
                    ? ExpertMaterialContextException::pdfSourceTooLarge()
                    : ExpertMaterialContextException::tooLarge();
            }

            if ($limits['kind'] !== 'pdf') {
                $totalNonPdfBytes += $bytes;
                if ($totalNonPdfBytes > $maxTotalBytes) {
                    throw ExpertMaterialContextException::tooLarge();
                }
            }

            $resolvedMaterials[] = ['material' => $material, 'bytes' => $bytes];
        }

        $maxCharsPerMaterial = max(1, (int) config('expert.material_context.max_extracted_chars_per_material', 30000));
        $maxTotalChars = max($maxCharsPerMaterial, (int) config('expert.material_context.max_total_extracted_chars', 80000));
        $totalNonPdfChars = 0;
        $context = [];

        foreach ($resolvedMaterials as $resolved) {
            $material = $resolved['material'];

            try {
                $contents = $disk->get($material->storage_path);
                $text = $this->extractor->extract(
                    $material,
                    $contents,
                    $disk->path($material->storage_path),
                );
            } catch (ExpertMaterialContextException $exception) {
                $this->logMaterialContextFailure($material, $resolved['bytes'], $exception);
                throw $exception;
            } catch (\Throwable $exception) {
                $failure = ExpertMaterialContextException::extractionFailed();
                $this->logMaterialContextFailure($material, $resolved['bytes'], $failure, $exception);
                throw $failure;
            }

            $chars = mb_strlen($text, 'UTF-8');
            if ($chars > $maxCharsPerMaterial) {
                if ($this->isPdf($material)) {
                    throw ExpertMaterialContextException::pdfTextTooLarge($this->pdfPageCount($contents), $chars);
                }

                $failure = ExpertMaterialContextException::tooLarge();
                $this->logMaterialContextFailure($material, $resolved['bytes'], $failure);
                throw $failure;
            }

            if (! $this->isPdf($material)) {
                $totalNonPdfChars += $chars;
                if ($totalNonPdfChars > $maxTotalChars) {
                    $failure = ExpertMaterialContextException::tooLarge();
                    $this->logMaterialContextFailure($material, $resolved['bytes'], $failure);
                    throw $failure;
                }
            }

            $this->identities->bestEffortEnrich($material, $text, 'local_text', 'local_extractor_v1', hash('sha256', $contents));

            $contextEntry = [
                'public_id' => (string) $material->public_id,
                'name' => ExpertMaterialPresentationName::resolve($material),
                'mime_type' => (string) $material->mime_type,
                'text' => $text,
                'source_bytes' => $resolved['bytes'],
                'extracted_chars' => $chars,
            ];
            if ($this->isPdf($material)) {
                $contextEntry += [
                    'processing_strategy' => 'local_text',
                    'page_count' => $this->pdfPageCount($contents),
                    'cache_hit' => false,
                ];
            }
            $context[] = $contextEntry;
        }

        return $context;
    }

    private function xlsxContextIsDisabled(ExpertProjectMaterial $material): bool
    {
        return strtolower(ltrim(trim((string) $material->extension), '.')) === 'xlsx'
            && ! (bool) config('expert.material_context.xlsx_enabled', false);
    }

    private function logMaterialContextFailure(
        ExpertProjectMaterial $material,
        ?int $bytes,
        ExpertMaterialContextException $failure,
        ?\Throwable $cause = null,
    ): void {
        Log::warning('Expert material context rejected.', array_filter([
            'material_public_id' => (string) $material->public_id,
            'mime_type' => (string) $material->mime_type,
            'extension' => strtolower(ltrim(trim((string) $material->extension), '.')),
            'bytes' => $bytes,
            'error_code' => $failure->errorCode,
            'cause' => $cause ? $cause::class : null,
        ], static fn (mixed $value): bool => $value !== null));
    }

    public function buildForChat(ExpertProject $project, array $publicIds, ?ExpertRunActivitySink $activity = null): ExpertMaterialContextBuildResult
    {
        $activity ??= new NoOpExpertRunActivitySink;
        if ($publicIds === []) {
            return new ExpertMaterialContextBuildResult([], []);
        }
        $textMaterials = [];
        $ocrCandidates = [];
        $ocrLimit = $this->pdfLimits()['max_source_bytes'];
        $ocrPages = 0;
        $ocrCount = 0;
        $uniqueIds = array_values(array_unique($publicIds));
        foreach ($uniqueIds as $index => $publicId) {
            $activityId = null;
            $analysisActivityId = null;
            try {
                if ($activity->isCancellationRequested()) {
                    throw new ExpertChatStreamingCancelledException;
                }
                /** @var ?ExpertProjectMaterial $material */
                $material = $project->materials()->where('public_id', $publicId)->first();
                $isPdf = $material !== null && $this->isPdf($material);
                $analysisActivityId = $activity->start(
                    ExpertAnalysisActivityCode::MATERIAL_STARTED,
                    'analysis',
                    ($index + 1).' из '.count($uniqueIds).': '.($material === null ? 'материал' : ExpertMaterialPresentationName::resolve($material)),
                );
                $activityId = $activity->start(
                    $isPdf ? 'pdf.local_extract.started' : 'material.text_extract.started',
                    'material',
                    $material === null ? null : ExpertMaterialPresentationName::resolve($material),
                );
                $textMaterials = [...$textMaterials, ...$this->build($project, [(string) $publicId])];
                $activity->complete($activityId, $isPdf ? 'pdf.local_extract.completed' : 'material.text_extract.completed');
                $activity->complete($analysisActivityId, ExpertAnalysisActivityCode::MATERIAL_COMPLETED);

                continue;
            } catch (ExpertChatStreamingCancelledException $exception) {
                throw $exception;
            } catch (ExpertMaterialContextException $exception) {
                $ocrReason = $exception->reason;
                if ($ocrReason !== null && str_starts_with($ocrReason, 'pdf_text_too_large:')) {
                    if ($activityId !== null) {
                        $activity->complete($activityId, 'pdf.local_extract.completed');
                    }
                    $details = explode(':', $ocrReason);
                    $material = $project->materials()->where('public_id', $publicId)->first();
                    if (! $material) {
                        throw ExpertMaterialContextException::notFound();
                    }
                    $disk = Storage::disk('local');
                    $raw = $disk->get($material->storage_path);
                    $bytes = strlen($raw);
                    $pageCount = (int) ($details[1] ?? 0);
                    $extractedChars = (int) ($details[2] ?? 0);
                    if ($bytes <= 0 || $bytes > $ocrLimit) {
                        throw ExpertPdfOcrException::processingTooLarge();
                    }
                    $ocrCount++;
                    if ($ocrCount > $this->pdfLimits()['max_documents']) {
                        throw ExpertPdfOcrException::tooManyPages();
                    }
                    if ($pageCount <= 0 || $pageCount > $this->pdfLimits()['max_pages_per_material']) {
                        throw ExpertPdfOcrException::tooManyPages();
                    }
                    $ocrPages += $pageCount;
                    if ($ocrPages > $this->pdfLimits()['max_total_pages']) {
                        throw ExpertPdfOcrException::tooManyPages();
                    }
                    $ocrCandidates[] = new ExpertPdfOcrCandidate(
                        (string) $project->public_id,
                        (string) $material->public_id,
                        ExpertMaterialPresentationName::resolve($material),
                        (string) $material->mime_type,
                        $raw,
                        hash('sha256', $raw),
                        $pageCount,
                        LLMFileProcessingIntent::PDF_TEXT_PARSE,
                        $extractedChars,
                    );
                    $activity->complete($analysisActivityId, ExpertAnalysisActivityCode::MATERIAL_COMPLETED);

                    continue;
                }
                if ($ocrReason === null || (! str_starts_with($ocrReason, 'pdf_no_usable_text:') && ! str_starts_with($ocrReason, 'pdf_local_extraction_failed:'))) {
                    if ($activityId !== null) {
                        $activity->fail($activityId, $exception->errorCode);
                    }
                    if ($analysisActivityId !== null) {
                        $activity->fail($analysisActivityId, $exception->errorCode, ExpertAnalysisActivityCode::MATERIAL_FAILED);
                    }
                    throw $exception;
                }
                $localFailure = str_starts_with($ocrReason, 'pdf_local_extraction_failed:');
                if ($activityId !== null) {
                    $activity->complete($activityId, $localFailure ? 'pdf.local_extract.unavailable' : 'pdf.local_extract.completed');
                }
                $pageCount = (int) substr($ocrReason, strlen($localFailure ? 'pdf_local_extraction_failed:' : 'pdf_no_usable_text:'));
                $material = $project->materials()->where('public_id', $publicId)->first();
                if (! $material) {
                    throw ExpertMaterialContextException::notFound();
                }
                $disk = Storage::disk('local');
                $bytes = (int) $disk->size($material->storage_path);
                if ($bytes <= 0 || $bytes > $ocrLimit) {
                    throw ExpertPdfOcrException::tooLarge();
                }
                $ocrCount++;
                if ($ocrCount > $this->pdfLimits()['max_documents']) {
                    throw ExpertPdfOcrException::tooManyPages();
                }
                if ($pageCount <= 0 || $pageCount > $this->pdfLimits()['max_pages_per_material']) {
                    throw ExpertPdfOcrException::tooManyPages();
                }
                $ocrPages += $pageCount;
                if ($ocrPages > $this->pdfLimits()['max_total_pages']) {
                    throw ExpertPdfOcrException::tooManyPages();
                }
                $raw = $disk->get($material->storage_path);
                $ocrCandidates[] = new ExpertPdfOcrCandidate(
                    (string) $project->public_id,
                    (string) $material->public_id,
                    ExpertMaterialPresentationName::resolve($material),
                    (string) $material->mime_type,
                    $raw,
                    hash('sha256', $raw),
                    $pageCount,
                );
                $activity->complete($analysisActivityId, ExpertAnalysisActivityCode::MATERIAL_COMPLETED);
            } catch (\Throwable $exception) {
                if ($activityId !== null) {
                    $activity->fail($activityId);
                }
                if ($analysisActivityId !== null) {
                    $activity->fail($analysisActivityId, $this->errorCode($exception), ExpertAnalysisActivityCode::MATERIAL_FAILED);
                }
                throw $exception;
            }
        }

        return new ExpertMaterialContextBuildResult($textMaterials, $ocrCandidates);
    }

    private function isPdf(ExpertProjectMaterial $material): bool
    {
        return strtolower(ltrim(trim((string) $material->extension), '.')) === 'pdf'
            || strtolower(trim((string) $material->mime_type)) === 'application/pdf';
    }

    private function pdfPageCount(string $contents): int
    {
        $count = preg_match_all('/\/Type\s*\/Page\b/', $contents);

        return max(1, is_int($count) ? $count : 0);
    }

    /** @return array{max_source_bytes: int, max_total_source_bytes: int, max_total_prepared_payload_bytes: int, max_extracted_chars: int, max_total_extracted_chars: int, max_documents: int, max_pages_per_material: int, max_total_pages: int, max_prepared_payload_bytes: int, kind: string} */
    private function pdfLimits(): array
    {
        return $this->limits->resolvePdf();
    }
}
