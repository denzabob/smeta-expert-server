<?php

namespace App\Services\Expert;

use App\Models\Expert\ExpertProjectMaterial;
use Throwable;

class ExpertMaterialThumbnailService
{
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct(private readonly ExpertStorageService $storage) {}

    /** @return array{path: string, fingerprint: string, last_modified: int} */
    public function ensure(ExpertProjectMaterial $material): array
    {
        $sourceDisk = $this->storage->diskForMaterial($material);
        $sourceKey = $this->storage->keyForMaterial($material);
        $context = $this->storage->contextForMaterial($material);
        if (! str_starts_with((string) $material->mime_type, 'image/')
            || ! $this->storage->exists($sourceDisk, $sourceKey, $context)) {
            throw new ExpertMaterialThumbnailException('Image thumbnail is unavailable.');
        }

        $sourceSize = $this->storage->size($sourceDisk, $sourceKey, $context);
        $lastModified = max(
            $this->storage->lastModified($sourceDisk, $sourceKey, $context),
            (int) ($material->updated_at?->timestamp ?: 0),
            1,
        );
        $limits = config('expert.material_thumbnails', []);
        $fingerprint = hash('sha256', implode('|', [
            $limits['cache_version'] ?? 'v1', $material->public_id, $sourceDisk, $sourceKey,
            (string) $sourceSize, (string) $lastModified, (string) ($material->updated_at?->timestamp ?: 0),
        ]));
        $directory = $this->cacheDirectory($material);
        $path = $directory.'/'.($limits['cache_version'] ?? 'v1').'-'.$fingerprint.'.jpg';
        $cacheDisk = $this->storage->cacheDisk();
        if ($this->storage->exists($cacheDisk, $path, $context)) {
            return ['path' => $path, 'fingerprint' => $fingerprint, 'last_modified' => $lastModified];
        }

        try {
            $bytes = $this->storage->withTemporaryFile($sourceDisk, $sourceKey, function (string $sourcePath) use ($sourceSize, $limits): string {
                $this->assertSourceWithinLimits($sourcePath, $sourceSize, $limits);
                $dimensions = @getimagesize($sourcePath);
                if (! is_array($dimensions) || empty($dimensions[0]) || empty($dimensions[1])) {
                    throw new ExpertMaterialThumbnailException('Image thumbnail is unavailable.');
                }
                $width = (int) $dimensions[0];
                $height = (int) $dimensions[1];
                $actualMime = (string) ($dimensions['mime'] ?? '');
                if (! in_array($actualMime, self::ALLOWED_MIME_TYPES, true)
                    || $width > (int) ($limits['max_width'] ?? 10000)
                    || $height > (int) ($limits['max_height'] ?? 10000)
                    || ($width * $height) > (int) ($limits['max_pixels'] ?? 25_000_000)) {
                    throw new ExpertMaterialThumbnailException('Image thumbnail is unavailable.');
                }

                $decoder = match ($actualMime) {
                    'image/jpeg' => 'imagecreatefromjpeg', 'image/png' => 'imagecreatefrompng',
                    'image/gif' => 'imagecreatefromgif', 'image/webp' => 'imagecreatefromwebp', default => null,
                };
                if (! $decoder || ! function_exists($decoder)) {
                    throw new ExpertMaterialThumbnailException('Image thumbnail is unavailable.');
                }

                try {
                    $source = @$decoder($sourcePath);
                    if (! $source) {
                        throw new ExpertMaterialThumbnailException('Image thumbnail is unavailable.');
                    }
                    $maxWidth = max(1, (int) ($limits['max_output_width'] ?? 320));
                    $maxHeight = max(1, (int) ($limits['max_output_height'] ?? 320));
                    $scale = min($maxWidth / $width, $maxHeight / $height, 1);
                    $outputWidth = max(1, (int) floor($width * $scale));
                    $outputHeight = max(1, (int) floor($height * $scale));
                    $canvas = imagecreatetruecolor($outputWidth, $outputHeight);
                    if (! $canvas) {
                        throw new ExpertMaterialThumbnailException('Image thumbnail is unavailable.');
                    }
                    $background = imagecolorallocate($canvas, 255, 255, 255);
                    imagefill($canvas, 0, 0, $background);
                    imagecopyresampled($canvas, $source, 0, 0, 0, 0, $outputWidth, $outputHeight, $width, $height);
                    ob_start();
                    imagejpeg($canvas, null, max(40, min(95, (int) ($limits['jpeg_quality'] ?? 82))));
                    $bytes = ob_get_clean();
                    if (! is_string($bytes) || $bytes === '') {
                        throw new ExpertMaterialThumbnailException('Image thumbnail is unavailable.');
                    }

                    return $bytes;
                } finally {
                    if (isset($canvas)) {
                        @imagedestroy($canvas);
                    }
                    if (isset($source)) {
                        @imagedestroy($source);
                    }
                }
            }, $context);

            // A changed source gets a new fingerprint; remove older variants before storing it.
            $this->storage->deleteDirectory($cacheDisk, $directory, $context);
            $this->storage->put($cacheDisk, $path, $bytes, $context);

            return ['path' => $path, 'fingerprint' => $fingerprint, 'last_modified' => $lastModified];
        } catch (ExpertMaterialThumbnailException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ExpertMaterialThumbnailException('Image thumbnail is unavailable.', 0, $exception);
        }
    }

    public function cacheDirectory(ExpertProjectMaterial $material): string
    {
        return 'expert/'.($material->project?->public_id ?? 'unknown').'/thumbnails/'.$material->public_id;
    }

    private function assertSourceWithinLimits(string $sourcePath, int $sourceSize, array $limits): void
    {
        if ($sourceSize <= 0 || $sourceSize > (int) ($limits['max_source_bytes'] ?? 15 * 1024 * 1024)) {
            throw new ExpertMaterialThumbnailException('Image thumbnail is unavailable.');
        }
        if (! class_exists(\finfo::class)) {
            throw new ExpertMaterialThumbnailException('Image thumbnail is unavailable.');
        }
        $signatureMime = (string) ((new \finfo(FILEINFO_MIME_TYPE))->file($sourcePath) ?: '');
        if (! in_array($signatureMime, self::ALLOWED_MIME_TYPES, true)) {
            throw new ExpertMaterialThumbnailException('Image thumbnail is unavailable.');
        }
    }
}
