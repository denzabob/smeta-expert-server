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
        $maxMaterialBytes = max(1, (int) config('expert.material_context.max_material_bytes', 5 * 1024 * 1024));
        $maxTotalBytes = max($maxMaterialBytes, (int) config('expert.material_context.max_total_material_bytes', 10 * 1024 * 1024));
        $totalBytes = 0;

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

            if ($bytes > $maxMaterialBytes) {
                throw ExpertMaterialContextException::tooLarge();
            }

            $totalBytes += $bytes;
            if ($totalBytes > $maxTotalBytes) {
                throw ExpertMaterialContextException::tooLarge();
            }

            $resolvedMaterials[] = ['material' => $material, 'bytes' => $bytes];
        }

        $maxCharsPerMaterial = max(1, (int) config('expert.material_context.max_extracted_chars_per_material', 30000));
        $maxTotalChars = max($maxCharsPerMaterial, (int) config('expert.material_context.max_total_extracted_chars', 80000));
        $totalChars = 0;
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

            $totalChars += $chars;
            if ($totalChars > $maxTotalChars) {
                $failure = ExpertMaterialContextException::tooLarge();
                $this->logMaterialContextFailure($material, $resolved['bytes'], $failure);
                throw $failure;
            }

            $contextEntry = [
                'public_id' => (string) $material->public_id,
                'name' => $this->presentationName($material),
                'mime_type' => (string) $material->mime_type,
                'text' => $text,
            ];
            if ($this->isPdf($material)) {
                $contextEntry += [
                    'processing_strategy' => 'local_text',
                    'source_bytes' => $resolved['bytes'],
                    'extracted_chars' => $chars,
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

    private function presentationName(ExpertProjectMaterial $material): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $material->original_name);
        $name = is_string($name) ? trim($name) : '';

        if ($name !== '') {
            return $name;
        }

        $extension = strtolower(ltrim(trim((string) $material->extension), '.'));

        return 'Материал без названия'.($extension === '' ? '' : '.'.$extension);
    }

    public function buildForChat(ExpertProject $project, array $publicIds, ?ExpertRunActivitySink $activity = null): ExpertMaterialContextBuildResult
    {
        $activity ??= new NoOpExpertRunActivitySink;
        if ($publicIds === []) {
            return new ExpertMaterialContextBuildResult([], []);
        }
        $textMaterials = [];
        $ocrCandidates = [];
        $ocrLimit = max(1, (int) config('expert.pdf_ocr.max_source_bytes', 20 * 1024 * 1024));
        $ocrPages = 0;
        $ocrCount = 0;
        foreach (array_values(array_unique($publicIds)) as $publicId) {
            $activityId = null;
            try {
                if ($activity->isCancellationRequested()) {
                    throw new ExpertChatStreamingCancelledException;
                }
                /** @var ?ExpertProjectMaterial $material */
                $material = $project->materials()->where('public_id', $publicId)->first();
                $isPdf = $material !== null && $this->isPdf($material);
                $activityId = $activity->start(
                    $isPdf ? 'pdf.local_extract.started' : 'material.text_extract.started',
                    'material',
                    $material === null ? null : $this->presentationName($material),
                );
                $textMaterials = [...$textMaterials, ...$this->build($project, [(string) $publicId])];
                $activity->complete($activityId, $isPdf ? 'pdf.local_extract.completed' : 'material.text_extract.completed');

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
                    if ($ocrCount > max(1, (int) config('expert.pdf_ocr.max_pdfs', 2))) {
                        throw ExpertPdfOcrException::tooManyPages();
                    }
                    if ($pageCount <= 0 || $pageCount > max(1, (int) config('expert.pdf_ocr.max_pages_per_pdf', 100))) {
                        throw ExpertPdfOcrException::tooManyPages();
                    }
                    $ocrPages += $pageCount;
                    if ($ocrPages > max(1, (int) config('expert.pdf_ocr.max_total_pages', 150))) {
                        throw ExpertPdfOcrException::tooManyPages();
                    }
                    $ocrCandidates[] = new ExpertPdfOcrCandidate(
                        (string) $project->public_id,
                        (string) $material->public_id,
                        $this->presentationName($material),
                        (string) $material->mime_type,
                        $raw,
                        hash('sha256', $raw),
                        $pageCount,
                        LLMFileProcessingIntent::PDF_TEXT_PARSE,
                        $extractedChars,
                    );

                    continue;
                }
                if ($ocrReason === null || (! str_starts_with($ocrReason, 'pdf_no_usable_text:') && ! str_starts_with($ocrReason, 'pdf_local_extraction_failed:'))) {
                    if ($activityId !== null) {
                        $activity->fail($activityId, $exception->errorCode);
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
                if ($ocrCount > max(1, (int) config('expert.pdf_ocr.max_pdfs', 2))) {
                    throw ExpertPdfOcrException::tooManyPages();
                }
                if ($pageCount <= 0 || $pageCount > max(1, (int) config('expert.pdf_ocr.max_pages_per_pdf', 100))) {
                    throw ExpertPdfOcrException::tooManyPages();
                }
                $ocrPages += $pageCount;
                if ($ocrPages > max(1, (int) config('expert.pdf_ocr.max_total_pages', 150))) {
                    throw ExpertPdfOcrException::tooManyPages();
                }
                $raw = $disk->get($material->storage_path);
                $ocrCandidates[] = new ExpertPdfOcrCandidate(
                    (string) $project->public_id,
                    (string) $material->public_id,
                    $this->presentationName($material),
                    (string) $material->mime_type,
                    $raw,
                    hash('sha256', $raw),
                    $pageCount,
                );
            } catch (\Throwable $exception) {
                if ($activityId !== null) {
                    $activity->fail($activityId);
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
}
