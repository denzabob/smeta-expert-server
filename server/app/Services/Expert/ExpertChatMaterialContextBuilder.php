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
    public function build(ExpertProject $project, array $publicIds): ExpertChatMaterialContext
    {
        if ($publicIds === []) return new ExpertChatMaterialContext([], []);

        $requestedIds = array_values(array_unique($publicIds));
        $materials = $project->materials()->whereIn('public_id', $requestedIds)->get()->keyBy('public_id');
        if ($materials->count() !== count($requestedIds)) throw ExpertMaterialContextException::notFound();

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
            try {
                $image = $this->imagePreparer->prepare($material);
                $totalPreparedBytes += strlen($image->bytes);
                if ($totalPreparedBytes > $maxTotalPreparedBytes) throw ExpertVisionException::tooLarge();
                $images[] = $image;
                Log::info('Expert vision image prepared.', ['material_public_id' => $image->materialPublicId, 'width' => $image->width, 'height' => $image->height, 'prepared_bytes' => strlen($image->bytes)]);
            } catch (ExpertVisionException $exception) {
                Log::warning('Expert vision image rejected.', ['material_public_id' => (string) $material->public_id, 'error_code' => $exception->errorCode]);
                throw $exception;
            }
        }

        $built = $this->textContextBuilder->buildForChat($project, $textIds);
        if ($built->ocrCandidates !== [] && ! (bool) config('expert.pdf_ocr.enabled', true)) throw ExpertPdfOcrException::disabled();
        $files = [];
        $pending = [];
        $totalFileBase64Bytes = 0;
        $textMaterials = $built->textMaterials;
        foreach ($built->ocrCandidates as $candidate) {
            $cached = $this->ocrCache->get($candidate);
            if ($cached !== null) {
                $textMaterials[] = ['public_id' => $candidate->materialPublicId, 'name' => $candidate->name, 'mime_type' => 'application/pdf', 'text' => $cached->text];
                foreach ($cached->images as $image) $images[] = new \App\Services\LLM\DTO\LLMImageContent($candidate->materialPublicId, $candidate->name, $image->mimeType, $image->bytes, 0, 0);
            } else {
                $totalFileBase64Bytes += strlen(base64_encode($candidate->bytes));
                if ($totalFileBase64Bytes > (int) config('expert.pdf_ocr.max_base64_request_bytes', 58720256)) throw ExpertPdfOcrException::tooLarge();
                $files[] = new LLMFileContent($candidate->name, 'application/pdf', $candidate->bytes, $candidate->sha256, LLMFileProcessingIntent::PDF_OCR);
                $pending[] = $candidate;
            }
        }
        return new ExpertChatMaterialContext($textMaterials, $images, $files, $pending);
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