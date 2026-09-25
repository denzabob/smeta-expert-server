<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Models\Expert\ExpertProjectMaterial;
use App\Services\LLM\DTO\LLMImageContent;
use Throwable;

final class ExpertVisionImagePreparer
{
    private const MIME_EXTENSIONS = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
    ];

    public function __construct(
        private readonly ExpertMaterialProcessingLimits $limitsResolver,
        private readonly ExpertStorageService $storage,
    ) {}

    public function prepare(ExpertProjectMaterial $material): LLMImageContent
    {
        $disk = $this->storage->diskForMaterial($material);
        $key = $this->storage->keyForMaterial($material);
        $context = $this->storage->contextForMaterial($material);
        $limits = $this->limitsResolver->resolve($material);

        try {
            if (! $this->storage->exists($disk, $key, $context)) {
                throw ExpertVisionException::invalid();
            }

            $sourceBytes = $this->storage->size($disk, $key, $context);
            if ($sourceBytes <= 0 || $sourceBytes > (int) ($limits['max_source_bytes'] ?? 15 * 1024 * 1024)) {
                throw ExpertVisionException::tooLarge();
            }

            return $this->storage->withTemporaryFile($disk, $key, function (string $sourcePath) use ($material, $sourceBytes, $limits): LLMImageContent {
                try {
                    $actualMime = $this->actualMime($sourcePath);
                    $extension = strtolower(ltrim(trim((string) $material->extension), '.'));
                    if (! isset(self::MIME_EXTENSIONS[$actualMime])
                        || strtolower(trim((string) $material->mime_type)) !== $actualMime
                        || ! in_array($extension, self::MIME_EXTENSIONS[$actualMime], true)) {
                        throw ExpertVisionException::invalid();
                    }

                    $dimensions = @getimagesize($sourcePath);
                    if (! is_array($dimensions) || (string) ($dimensions['mime'] ?? '') !== $actualMime) {
                        throw ExpertVisionException::invalid();
                    }

                    $width = (int) ($dimensions[0] ?? 0);
                    $height = (int) ($dimensions[1] ?? 0);
                    $this->assertDimensions($width, $height, $limits);

                    $decoder = match ($actualMime) {
                        'image/jpeg' => 'imagecreatefromjpeg',
                        'image/png' => 'imagecreatefrompng',
                        'image/webp' => 'imagecreatefromwebp',
                        default => null,
                    };
                    if ($decoder === null || ! function_exists($decoder)) {
                        throw ExpertVisionException::preparationFailed();
                    }

                    $source = @$decoder($sourcePath);
                    if (! $source) {
                        throw ExpertVisionException::invalid();
                    }

                    $source = $this->applyOrientation($source, $actualMime === 'image/jpeg'
                        ? $this->readJpegExifOrientation($sourcePath)
                        : 1);
                    $width = imagesx($source);
                    $height = imagesy($source);

                    $maxOutputWidth = max(1, $limits['max_output_width']);
                    $maxOutputHeight = max(1, $limits['max_output_height']);
                    $scale = min($maxOutputWidth / $width, $maxOutputHeight / $height, 1);
                    $outputWidth = max(1, (int) floor($width * $scale));
                    $outputHeight = max(1, (int) floor($height * $scale));
                    $canvas = imagecreatetruecolor($outputWidth, $outputHeight);
                    if (! $canvas) {
                        throw ExpertVisionException::preparationFailed();
                    }

                    $background = imagecolorallocate($canvas, 255, 255, 255);
                    imagefill($canvas, 0, 0, $background);
                    imagealphablending($canvas, true);
                    if (! imagecopyresampled(
                        $canvas, $source,
                        0, 0, 0, 0,
                        $outputWidth, $outputHeight, $width, $height,
                    )) {
                        throw ExpertVisionException::preparationFailed();
                    }

                    ob_start();
                    $encoded = imagejpeg($canvas, null, max(40, min(95, $limits['jpeg_quality'])));
                    $bytes = ob_get_clean();
                    if (! $encoded || ! is_string($bytes) || $bytes === '') {
                        throw ExpertVisionException::preparationFailed();
                    }

                    if (strlen($bytes) > $limits['max_prepared_payload_bytes']) {
                        throw ExpertVisionException::tooLarge();
                    }

                    return new LLMImageContent(
                        materialPublicId: (string) $material->public_id,
                        name: $this->presentationName($material),
                        mimeType: 'image/jpeg',
                        bytes: $bytes,
                        width: $outputWidth,
                        height: $outputHeight,
                        sourceBytes: $sourceBytes,
                    );
                } finally {
                    if (isset($canvas)) {
                        @imagedestroy($canvas);
                    }
                    if (isset($source)) {
                        @imagedestroy($source);
                    }
                }
            }, $context);
        } catch (ExpertVisionException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw ExpertVisionException::preparationFailed($exception);
        }
    }

    private function actualMime(string $sourcePath): string
    {
        if (! class_exists(\finfo::class)) {
            throw ExpertVisionException::preparationFailed();
        }

        return (string) ((new \finfo(FILEINFO_MIME_TYPE))->file($sourcePath) ?: '');
    }

    private function assertDimensions(int $width, int $height, array $limits): void
    {
        $maxWidth = max(1, $limits['max_width']);
        $maxHeight = max(1, $limits['max_height']);
        $maxPixels = max(1, $limits['max_pixels']);

        if ($width <= 0 || $height <= 0
            || $width > $maxWidth
            || $height > $maxHeight
            || $width > intdiv($maxPixels, $height)) {
            throw ExpertVisionException::tooLarge();
        }
    }

    /** @param \GdImage|resource $source @return \GdImage|resource */
    private function applyOrientation(mixed $source, int $orientation): mixed
    {
        if (in_array($orientation, [2, 4, 5, 7], true) && function_exists('imageflip')) {
            imageflip($source, in_array($orientation, [2, 5], true) ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL);
        }

        $angle = match ($orientation) {
            3 => 180,
            5, 8 => 90,
            6, 7 => 270,
            default => 0,
        };

        if ($angle === 0) {
            return $source;
        }

        $rotated = imagerotate($source, $angle, 0xFFFFFF);
        if (! $rotated) {
            throw ExpertVisionException::preparationFailed();
        }

        imagedestroy($source);

        return $rotated;
    }

    private function readJpegExifOrientation(string $sourcePath): int
    {
        $data = @file_get_contents($sourcePath, false, null, 0, 262144);
        if (! is_string($data) || strlen($data) < 12 || substr($data, 0, 2) !== "\xFF\xD8") {
            return 1;
        }

        $length = strlen($data);
        $offset = 2;
        while ($offset + 4 <= $length) {
            if (ord($data[$offset]) !== 0xFF) {
                break;
            }
            while ($offset < $length && ord($data[$offset]) === 0xFF) {
                $offset++;
            }
            if ($offset >= $length) {
                break;
            }

            $marker = ord($data[$offset++]);
            if (in_array($marker, [0xD8, 0xD9, 0x01], true)) {
                continue;
            }
            if ($marker === 0xDA || $offset + 2 > $length) {
                break;
            }

            $segmentLength = unpack('nlength', substr($data, $offset, 2))['length'] ?? 0;
            if ($segmentLength < 2 || $offset + $segmentLength > $length) {
                break;
            }

            $segmentStart = $offset + 2;
            if ($marker === 0xE1 && substr($data, $segmentStart, 6) === "Exif\0\0") {
                return $this->orientationFromTiff($data, $segmentStart + 6, $offset + $segmentLength);
            }

            $offset += $segmentLength;
        }

        return 1;
    }

    private function orientationFromTiff(string $data, int $tiffStart, int $segmentEnd): int
    {
        if ($tiffStart + 8 > $segmentEnd) {
            return 1;
        }

        $byteOrder = substr($data, $tiffStart, 2);
        $littleEndian = $byteOrder === 'II';
        if (! $littleEndian && $byteOrder !== 'MM') {
            return 1;
        }

        $readU16 = static function (int $offset) use ($data, $segmentEnd, $littleEndian): ?int {
            if ($offset < 0 || $offset + 2 > $segmentEnd) {
                return null;
            }
            $value = unpack($littleEndian ? 'vvalue' : 'nvalue', substr($data, $offset, 2));

            return isset($value['value']) ? (int) $value['value'] : null;
        };
        $readU32 = static function (int $offset) use ($data, $segmentEnd, $littleEndian): ?int {
            if ($offset < 0 || $offset + 4 > $segmentEnd) {
                return null;
            }
            $value = unpack($littleEndian ? 'Vvalue' : 'Nvalue', substr($data, $offset, 4));

            return isset($value['value']) ? (int) $value['value'] : null;
        };

        if ($readU16($tiffStart + 2) !== 42) {
            return 1;
        }
        $ifdOffset = $readU32($tiffStart + 4);
        if ($ifdOffset === null) {
            return 1;
        }

        $ifdStart = $tiffStart + $ifdOffset;
        $entryCount = $readU16($ifdStart);
        if ($entryCount === null || $entryCount > 256) {
            return 1;
        }

        for ($index = 0; $index < $entryCount; $index++) {
            $entry = $ifdStart + 2 + ($index * 12);
            if ($entry + 12 > $segmentEnd) {
                return 1;
            }
            if ($readU16($entry) !== 0x0112 || $readU16($entry + 2) !== 3 || $readU32($entry + 4) !== 1) {
                continue;
            }

            $orientation = $readU16($entry + 8);

            return $orientation !== null && $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
        }

        return 1;
    }

    private function presentationName(ExpertProjectMaterial $material): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $material->original_name);
        $name = is_string($name) ? trim($name) : '';

        return $name !== '' ? $name : 'Изображение без названия';
    }
}
