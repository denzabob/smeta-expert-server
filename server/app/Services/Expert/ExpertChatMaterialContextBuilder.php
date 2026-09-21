<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Models\Expert\ExpertProject;
use App\Models\Expert\ExpertProjectMaterial;
use App\Services\LLM\DTO\LLMFileContent;
use App\Services\LLM\Enums\LLMFileProcessingIntent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

final class ExpertChatMaterialContextBuilder
{
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];

    public function __construct(
        private readonly ExpertMaterialContextBuilder $textContextBuilder,
        private readonly ExpertVisionImagePreparer $imagePreparer,
        private readonly ExpertPdfOcrCache $ocrCache,
    ) {}

    /** @param list<string> $publicIds */
    public function build(ExpertProject $project, array $publicIds, ?ExpertRunActivitySink $activity = null): ExpertChatMaterialContext
    {
        $activity ??= new NoOpExpertRunActivitySink();
        if ($publicIds === []) {
            $activity->record('context.build.completed', 'context');

            return new ExpertChatMaterialContext([], []);
        }

        $requestedIds = array_values(array_unique($publicIds));
        $resolveActivity = $activity->start('materials.resolve.started', 'material');
        try {
            $this->throwIfCancellationRequested($activity);
            $materials = $project->materials()->whereIn('public_id', $requestedIds)->get()->keyBy('public_id');
            if ($materials->count() !== count($requestedIds)) throw ExpertMaterialContextException::notFound();
        } catch (ExpertChatStreamingCancelledException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $activity->fail($resolveActivity, $this->errorCode($exception));
            throw $exception;
        }
        $activity->complete($resolveActivity, 'materials.resolve.completed');

        $imageMaterials = [];
        $textIds = [];
        foreach ($requestedIds as $publicId) {
            $material = $materials->get($publicId);
            if ($this->isImageCandidate($material)) $imageMaterials[] = $material;
            else $textIds[] = $publicId;
        }

        $maxImages = max(1, (int) config('expert.vision.max_images_per_message', 4));
        if (count($imageMaterials) > $maxImages) throw ExpertVisionException::tooMany();

        $images = [];
        $totalPreparedBytes = 0;
        $maxTotalPreparedBytes = max(1, (int) config('expert.vision.max_total_vision_bytes', 12 * 1024 * 1024));
        foreach ($imageMaterials as $material) {
            $this->throwIfCancellationRequested($activity);
            $imageActivity = $activity->start('material.image_prepare.started', 'material', (string) $material->original_name);
            try {
                $image = $this->imagePreparer->prepare($material);
                $totalPreparedBytes += strlen($image->bytes);
                if ($totalPreparedBytes > $maxTotalPreparedBytes) throw ExpertVisionException::tooLarge();
                $images[] = $image;
                $activity->complete($imageActivity, 'material.image_prepare.completed');
                Log::info('Expert vision image prepared.', ['material_public_id' => $image->materialPublicId, 'width' => $image->width, 'height' => $image->height, 'prepared_bytes' => strlen($image->bytes)]);
            } catch (ExpertVisionException $exception) {
                $activity->fail($imageActivity, $exception->errorCode);
                Log::warning('Expert vision image rejected.', ['material_public_id' => (string) $material->public_id, 'error_code' => $exception->errorCode]);
                throw $exception;
            } catch (\Throwable $exception) {
                $activity->fail($imageActivity, $this->errorCode($exception));
                throw $exception;
            }
        }

        $this->throwIfCancellationRequested($activity);
        $built = $this->textContextBuilder->buildForChat($project, $textIds, $activity);
        $ocrCandidates = array_values(array_filter(
            $built->ocrCandidates,
            static fn (ExpertPdfOcrCandidate $candidate): bool => $candidate->processingIntent === LLMFileProcessingIntent::PDF_OCR,
        ));
        if ($ocrCandidates !== [] && ! (bool) config('expert.pdf_ocr.enabled', true)) throw ExpertPdfOcrException::disabled();
        $files = [];
        $pending = [];
        $totalFileBase64Bytes = 0;
        $textMaterials = $built->textMaterials;
        foreach ($built->ocrCandidates as $candidate) {
            $this->throwIfCancellationRequested($activity);
            $cached = $this->ocrCache->get($candidate);
            $cachePrefix = $candidate->processingIntent === LLMFileProcessingIntent::PDF_TEXT_PARSE
                ? 'pdf.text_cache'
                : 'pdf.ocr_cache';
            if ($cached !== null) {
                $activity->record($cachePrefix.'.hit', 'material', $candidate->name);
                $textMaterials[] = [
                    'public_id' => $candidate->materialPublicId,
                    'name' => $candidate->name,
                    'mime_type' => 'application/pdf',
                    'text' => $cached->text,
                    'processing_strategy' => $candidate->processingStrategy(),
                    'source_bytes' => strlen($candidate->bytes),
                    'extracted_chars' => mb_strlen($cached->text, 'UTF-8'),
                    'page_count' => $candidate->pageCount,
                    'cache_hit' => true,
                ];
                foreach ($cached->images as $image) $images[] = new \App\Services\LLM\DTO\LLMImageContent($candidate->materialPublicId, $candidate->name, $image->mimeType, $image->bytes, 0, 0);
            } else {
                $activity->record($cachePrefix.'.miss', 'material', $candidate->name);
                $totalFileBase64Bytes += strlen(base64_encode($candidate->bytes));
                if ($totalFileBase64Bytes > (int) config('expert.pdf_ocr.max_base64_request_bytes', 56 * 1024 * 1024)) {
                    throw $candidate->processingIntent === LLMFileProcessingIntent::PDF_TEXT_PARSE
                        ? ExpertPdfOcrException::processingTooLarge()
                        : ExpertPdfOcrException::tooLarge();
                }
                $files[] = new LLMFileContent($candidate->name, 'application/pdf', $candidate->bytes, $candidate->sha256, $candidate->processingIntent);
                $pending[] = $candidate;
            }
        }

        $activity->record('context.build.completed', 'context');

        return new ExpertChatMaterialContext($textMaterials, $images, $files, $pending);
    }

    /**
     * Builds both origins in one pass so all existing aggregate material,
     * image and OCR limits continue to apply to the complete request.
     *
     * @param  list<string>  $currentIds
     * @param  list<string>  $historicalIds
     */
    public function buildPartitioned(
        ExpertProject $project,
        array $currentIds,
        array $historicalIds,
        ?ExpertRunActivitySink $activity = null,
    ): ExpertChatMaterialContextBundle {
        $currentIds = array_values(array_unique($currentIds));
        $historicalIds = array_values(array_diff(array_unique($historicalIds), $currentIds));
        $combined = $this->build($project, [...$currentIds, ...$historicalIds], $activity);

        return ExpertChatMaterialContextBundle::partition($combined, $currentIds, $historicalIds);
    }

    private function throwIfCancellationRequested(ExpertRunActivitySink $activity): void
    {
        if ($activity->isCancellationRequested()) {
            throw new ExpertChatStreamingCancelledException();
        }
    }

    private function errorCode(\Throwable $exception): ?string
    {
        return property_exists($exception, 'errorCode') && is_string($exception->errorCode)
            ? $exception->errorCode
            : null;
    }

    private function isImageCandidate(ExpertProjectMaterial $material): bool
    {
        $extension = strtolower(ltrim(trim((string) $material->extension), '.'));
        $declaredMime = strtolower(trim((string) $material->mime_type));
        if (in_array($extension, self::IMAGE_EXTENSIONS, true) || str_starts_with($declaredMime, 'image/')) return true;
        $disk = Storage::disk('local');
        if (! $disk->exists($material->storage_path) || ! class_exists(\finfo::class)) return false;
        $actualMime = (string) ((new \finfo(FILEINFO_MIME_TYPE))->file($disk->path($material->storage_path)) ?: '');
        return str_starts_with($actualMime, 'image/');
    }
}
