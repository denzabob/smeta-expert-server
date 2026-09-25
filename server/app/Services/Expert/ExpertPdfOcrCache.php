<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Models\Expert\ExpertProjectMaterial;
use App\Services\LLM\DTO\LLMParsedFile;
use App\Services\LLM\Enums\LLMFileProcessingIntent;

final class ExpertPdfOcrCache
{
    public function __construct(private readonly ExpertStorageService $storage) {}

    public function cacheDirectory(ExpertProjectMaterial $material): string
    {
        return 'expert/'.($material->project?->public_id ?? 'unknown').'/ocr/'.$material->public_id;
    }

    public function path(ExpertPdfOcrCandidate $candidate): string
    {
        return 'expert/'.$candidate->projectPublicId
            .'/ocr/'.$candidate->materialPublicId
            .'/'.$candidate->processingIntent->value
            .'/'.rawurlencode($this->processingEngine($candidate->processingIntent))
            .'/'.config('expert.pdf_ocr.cache_version', 'v1').'-'.$candidate->sha256.'.json';
    }

    public function get(ExpertPdfOcrCandidate $candidate): ?LLMParsedFile
    {
        $disk = $this->storage->cacheDisk();
        $context = [
            'project_public_id' => $candidate->projectPublicId,
            'material_public_id' => $candidate->materialPublicId,
        ];
        $path = $this->path($candidate);
        $legacyPath = $candidate->processingIntent === LLMFileProcessingIntent::PDF_OCR
            && $this->processingEngine($candidate->processingIntent) === 'mistral-ocr'
            ? $this->legacyPath($candidate)
            : null;

        foreach (array_values(array_filter([$path, $legacyPath])) as $candidatePath) {
            if (! $this->storage->exists($disk, $candidatePath, $context)) {
                continue;
            }

            $raw = $this->storage->read($disk, $candidatePath, $context);
            $data = json_decode($raw, true);
            $isLegacyOcrPath = $legacyPath !== null && $candidatePath === $legacyPath;
            $this->assertPayload($data, $candidate, $isLegacyOcrPath);

            $images = [];
            $totalImageBytes = 0;
            if (count($data['images']) > (int) config('expert.pdf_ocr.max_annotation_images_per_pdf', 8)) {
                throw ExpertPdfOcrException::cacheInvalid();
            }

            foreach ($data['images'] as $image) {
                if (! is_array($image)
                    || ! in_array($image['mime_type'] ?? null, ['image/png', 'image/jpeg', 'image/webp'], true)
                    || ! is_string($image['bytes_base64'] ?? null)) {
                    throw ExpertPdfOcrException::cacheInvalid();
                }

                $bytes = base64_decode($image['bytes_base64'], true);
                if ($bytes === false) {
                    throw ExpertPdfOcrException::cacheInvalid();
                }

                $totalImageBytes += strlen($bytes);
                $images[] = new \App\Services\LLM\DTO\LLMParsedFileImage($image['mime_type'], $bytes);
            }

            if ($totalImageBytes > (int) config('expert.pdf_ocr.max_annotation_images_bytes', 20 * 1024 * 1024)
                || mb_strlen($data['text'], 'UTF-8') > (int) config('expert.pdf_ocr.max_annotation_text_chars', 500000)) {
                throw ExpertPdfOcrException::cacheInvalid();
            }

            return new LLMParsedFile(
                $candidate->sha256,
                (string) ($data['name'] ?? $candidate->name),
                $data['text'],
                $images,
            );
        }

        return null;
    }

    public function put(ExpertPdfOcrCandidate $candidate, LLMParsedFile $parsed): void
    {
        if (strtolower($parsed->sha256) !== strtolower($candidate->sha256) || $parsed->text === '') {
            throw ExpertPdfOcrException::cacheInvalid();
        }

        $payload = [
            'version' => config('expert.pdf_ocr.cache_version', 'v1'),
            'project_public_id' => $candidate->projectPublicId,
            'material_public_id' => $candidate->materialPublicId,
            'source_sha256' => $candidate->sha256,
            'processing_intent' => $candidate->processingIntent->value,
            'processing_engine' => $this->processingEngine($candidate->processingIntent),
            'name' => $candidate->name,
            'text' => $parsed->text,
            'images' => array_map(
                static fn ($image): array => [
                    'mime_type' => $image->mimeType,
                    'bytes_base64' => base64_encode($image->bytes),
                ],
                $parsed->images,
            ),
        ];
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > (int) config('expert.pdf_ocr.max_cache_bytes', 32 * 1024 * 1024)) {
            throw ExpertPdfOcrException::cacheInvalid();
        }

        $this->storage->put($this->storage->cacheDisk(), $this->path($candidate), $json, [
            'project_public_id' => $candidate->projectPublicId,
            'material_public_id' => $candidate->materialPublicId,
        ]);
    }

    public function processingEngine(LLMFileProcessingIntent $intent): string
    {
        return $intent === LLMFileProcessingIntent::PDF_TEXT_PARSE
            ? (string) config('expert.pdf_processing.text_engine', 'cloudflare-ai')
            : (string) config('expert.pdf_processing.ocr_engine', config('expert.pdf_ocr.engine', 'mistral-ocr'));
    }

    private function legacyPath(ExpertPdfOcrCandidate $candidate): string
    {
        return 'expert/'.$candidate->projectPublicId.'/ocr/'.$candidate->materialPublicId.'/'.config('expert.pdf_ocr.cache_version', 'v1').'-'.$candidate->sha256.'.json';
    }

    private function assertPayload(mixed $data, ExpertPdfOcrCandidate $candidate, bool $legacy): void
    {
        if (! is_array($data)
            || ($data['project_public_id'] ?? null) !== $candidate->projectPublicId
            || ($data['material_public_id'] ?? null) !== $candidate->materialPublicId
            || ($data['source_sha256'] ?? null) !== $candidate->sha256
            || ! is_string($data['text'] ?? null)
            || ! is_array($data['images'] ?? null)) {
            throw ExpertPdfOcrException::cacheInvalid();
        }

        if (($data['processing_intent'] ?? LLMFileProcessingIntent::PDF_OCR->value) !== $candidate->processingIntent->value) {
            throw ExpertPdfOcrException::cacheInvalid();
        }

        if (! $legacy && ($data['processing_engine'] ?? null) !== $this->processingEngine($candidate->processingIntent)) {
            throw ExpertPdfOcrException::cacheInvalid();
        }
    }
}
