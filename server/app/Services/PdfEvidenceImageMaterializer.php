<?php

namespace App\Services;

use App\Services\Storage\ObjectStorage;
use Closure;

/**
 * Materializes private evidence images only while a PDF renderer is using them.
 */
class PdfEvidenceImageMaterializer
{
    private const PDF_TEMPORARY_DIRECTORY = 'app';

    public function __construct(private ObjectStorage $storage) {}

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function withPriceJustificationImages(array $rows, Closure $render): mixed
    {
        $temporaryPaths = [];
        $materialized = [];

        try {
            foreach ($rows as &$row) {
                $screenshotPath = $row['screenshot_path'] ?? null;
                if (!is_string($screenshotPath) || trim($screenshotPath) === '') {
                    $screenshotPath = $row['snapshot_path'] ?? null;
                }

                if (is_string($screenshotPath) && trim($screenshotPath) !== '') {
                    $disk = $this->storage->resolveDisk($row['storage_disk'] ?? null, $screenshotPath);
                    $row['pdf_local_path'] = $this->materialize(
                        $disk,
                        $screenshotPath,
                        $temporaryPaths,
                        $materialized,
                    );
                }

                $assetPaths = [];
                foreach ((array) data_get($row, 'source_level_snapshot.sources', []) as $source) {
                    foreach ((array) ($source['evidence_assets'] ?? []) as $asset) {
                        $path = data_get($asset, 'storage_reference.path') ?: ($asset['file_path'] ?? null);
                        $mimeType = (string) ($asset['mime_type'] ?? '');
                        if (!is_string($path) || trim($path) === '' || !str_starts_with($mimeType, 'image/')) {
                            continue;
                        }

                        $disk = $this->storage->resolveDisk(
                            data_get($asset, 'storage_reference.disk') ?? ($asset['storage_disk'] ?? null),
                            $path,
                        );
                        $assetId = (int) (data_get($asset, 'asset_ref.id') ?? $asset['asset_id'] ?? $asset['id'] ?? 0);
                        if ($assetId > 0) {
                            $assetPaths[$assetId] = $this->materialize(
                                $disk,
                                $path,
                                $temporaryPaths,
                                $materialized,
                            );
                        }
                    }
                }

                if ($assetPaths !== []) {
                    $row['facade_snapshot_presentation']['sources'] ??= [];
                    $this->attachPdfAssetPaths($row['facade_snapshot_presentation']['sources'], $assetPaths);
                }
            }
            unset($row);

            return $render($rows);
        } finally {
            foreach (array_unique($temporaryPaths) as $temporaryPath) {
                @unlink($temporaryPath);
            }
        }
    }

    /**
     * @param array<string, mixed> $viewData
     */
    public function withEvidenceRunImages(array $viewData, Closure $render): mixed
    {
        $temporaryPaths = [];
        $materialized = [];

        try {
            $this->materializeEvidenceRunImages($viewData, $temporaryPaths, $materialized);

            return $render($viewData);
        } finally {
            foreach (array_unique($temporaryPaths) as $temporaryPath) {
                @unlink($temporaryPath);
            }
        }
    }

    /**
     * @param array<int, string> $temporaryPaths
     * @param array<string, string> $materialized
     */
    private function materialize(
        string $disk,
        string $path,
        array &$temporaryPaths,
        array &$materialized,
    ): string {
        $cacheKey = $disk . ':' . $path;
        if (isset($materialized[$cacheKey])) {
            return $materialized[$cacheKey];
        }

        $temporaryPath = $this->storage->materializeTemporaryFile(
            $disk,
            $path,
            pathinfo($path, PATHINFO_EXTENSION),
            storage_path(self::PDF_TEMPORARY_DIRECTORY),
        );
        $temporaryPaths[] = $temporaryPath;
        $materialized[$cacheKey] = $temporaryPath;

        return $temporaryPath;
    }

    /**
     * @param array<string, mixed> $value
     * @param array<int, string> $temporaryPaths
     * @param array<string, string> $materialized
     */
    private function materializeEvidenceRunImages(array &$value, array &$temporaryPaths, array &$materialized): void
    {
        $path = $value['image_path'] ?? null;
        if (is_string($path) && trim($path) !== '' && !empty($value['image_exists'])) {
            $disk = $this->storage->resolveDisk($value['image_storage_disk'] ?? null, $path);
            $value['image_local_path'] = $this->materialize($disk, $path, $temporaryPaths, $materialized);
        }

        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->materializeEvidenceRunImages($item, $temporaryPaths, $materialized);
            }
        }
        unset($item);
    }

    /**
     * @param array<int, array<string, mixed>> $sources
     * @param array<int, string> $assetPaths
     */
    private function attachPdfAssetPaths(array &$sources, array $assetPaths): void
    {
        foreach ($sources as &$source) {
            if (!is_array($source['evidence_assets'] ?? null)) {
                continue;
            }

            foreach ($source['evidence_assets'] as &$asset) {
                $assetId = (int) ($asset['asset_id'] ?? 0);
                if (isset($assetPaths[$assetId])) {
                    $asset['pdf_local_path'] = $assetPaths[$assetId];
                }
            }
            unset($asset);
        }
        unset($source);
    }
}
