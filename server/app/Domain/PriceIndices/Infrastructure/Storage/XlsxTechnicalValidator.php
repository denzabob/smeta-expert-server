<?php

namespace App\Domain\PriceIndices\Infrastructure\Storage;

use App\Domain\PriceIndices\Application\Data\XlsxTechnicalValidationResult;
use App\Domain\PriceIndices\Domain\Enums\SourceFileErrorCode;
use App\Domain\PriceIndices\Domain\Enums\ValidationStatus;
use App\Domain\PriceIndices\Domain\Exceptions\XlsxValidationException;
use finfo;
use ZipArchive;

class XlsxTechnicalValidator
{
    private const REQUIRED_CONTENT_TYPES = '[Content_Types].xml';
    private const REQUIRED_WORKBOOK = 'xl/workbook.xml';

    /** @var list<string> */
    private const EXECUTABLE_EXTENSIONS = [
        'exe', 'dll', 'com', 'bat', 'cmd', 'ps1', 'scr', 'msi', 'jar', 'vbs', 'js', 'jse',
    ];

    public function validate(
        string $absolutePath,
        string $originalFilename,
        ?string $declaredMimeType
    ): XlsxTechnicalValidationResult {
        if (! is_file($absolutePath)) {
            $this->fail(SourceFileErrorCode::FileMissing, 'The uploaded file is missing.');
        }

        $size = filesize($absolutePath);

        if ($size === false || $size === 0) {
            $this->fail(SourceFileErrorCode::FileEmpty, 'The uploaded file is empty.');
        }

        if ($size > (int) config('price_indices.source_files.max_upload_bytes')) {
            $this->fail(SourceFileErrorCode::FileTooLarge, 'The uploaded file exceeds the size limit.');
        }

        if (strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION)) !== 'xlsx') {
            $this->fail(SourceFileErrorCode::InvalidExtension, 'Only .xlsx files are accepted.');
        }

        $allowedMimeTypes = config('price_indices.xlsx.allowed_mime_types', []);
        $detectedMimeType = (new finfo(FILEINFO_MIME_TYPE))->file($absolutePath) ?: null;

        if (($declaredMimeType !== null && ! in_array($declaredMimeType, $allowedMimeTypes, true))
            || $detectedMimeType === null
            || ! in_array($detectedMimeType, $allowedMimeTypes, true)
        ) {
            $this->fail(SourceFileErrorCode::InvalidMime, 'The uploaded file MIME type is not allowed.');
        }

        $signatureStream = fopen($absolutePath, 'rb');
        $signature = $signatureStream === false ? false : fread($signatureStream, 4);

        if (is_resource($signatureStream)) {
            fclose($signatureStream);
        }

        if ($signature !== "PK\x03\x04") {
            $this->fail(SourceFileErrorCode::InvalidZipSignature, 'The uploaded file is not a ZIP-based XLSX.');
        }

        $zip = new ZipArchive();

        if ($zip->open($absolutePath, ZipArchive::RDONLY) !== true) {
            $this->fail(SourceFileErrorCode::InvalidZip, 'The XLSX archive cannot be opened.');
        }

        try {
            $this->validateArchive($zip);
        } finally {
            $zip->close();
        }

        $warnings = [];

        if ($declaredMimeType !== 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') {
            $warnings[] = 'generic_mime_type';
        }

        return new XlsxTechnicalValidationResult(
            $warnings === [] ? ValidationStatus::Passed : ValidationStatus::Warning,
            $warnings
        );
    }

    private function validateArchive(ZipArchive $zip): void
    {
        if ($zip->numFiles > (int) config('price_indices.xlsx.max_zip_entries')) {
            $this->fail(SourceFileErrorCode::TooManyZipEntries, 'The XLSX archive contains too many entries.');
        }

        $totalUncompressed = 0;
        $hasContentTypes = false;
        $hasWorkbook = false;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);

            if ($stat === false) {
                $this->fail(SourceFileErrorCode::InvalidZip, 'The XLSX archive contains an unreadable entry.');
            }

            $name = (string) ($stat['name'] ?? '');
            $size = (int) ($stat['size'] ?? 0);
            $compressedSize = (int) ($stat['comp_size'] ?? 0);

            $this->validateEntryName($name);
            $this->validateEntryContentPolicy($name);

            if ($size > (int) config('price_indices.xlsx.max_single_entry_uncompressed_bytes')) {
                $this->fail(SourceFileErrorCode::EntryTooLarge, 'An XLSX archive entry exceeds the size limit.');
            }

            $totalUncompressed += $size;

            if ($totalUncompressed > (int) config('price_indices.xlsx.max_total_uncompressed_bytes')) {
                $this->fail(SourceFileErrorCode::UncompressedSizeLimit, 'The XLSX archive exceeds the total size limit.');
            }

            if ($size > 0 && ($size / max(1, $compressedSize)) > (float) config('price_indices.xlsx.max_compression_ratio')) {
                $this->fail(SourceFileErrorCode::CompressionRatioLimit, 'The XLSX archive compression ratio is unsafe.');
            }

            $hasContentTypes = $hasContentTypes || $name === self::REQUIRED_CONTENT_TYPES;
            $hasWorkbook = $hasWorkbook || $name === self::REQUIRED_WORKBOOK;
        }

        if (! $hasContentTypes) {
            $this->fail(SourceFileErrorCode::MissingContentTypes, 'The XLSX content types declaration is missing.');
        }

        if (! $hasWorkbook) {
            $this->fail(SourceFileErrorCode::MissingWorkbook, 'The XLSX workbook declaration is missing.');
        }

        if ($this->entryContains($zip, self::REQUIRED_CONTENT_TYPES, 'macroEnabled')) {
            $this->fail(SourceFileErrorCode::MacrosNotAllowed, 'Macro-enabled workbooks are not accepted.');
        }
    }

    private function validateEntryName(string $name): void
    {
        if ($name === ''
            || str_contains($name, "\0")
            || str_starts_with($name, '/')
            || str_starts_with($name, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $name) === 1
            || in_array('..', preg_split('/[\\\\\/]+/', $name) ?: [], true)
        ) {
            $this->fail(SourceFileErrorCode::PathTraversal, 'The XLSX archive contains an unsafe entry path.');
        }
    }

    private function validateEntryContentPolicy(string $name): void
    {
        $normalized = strtolower(str_replace('\\', '/', $name));
        $extension = strtolower(pathinfo($normalized, PATHINFO_EXTENSION));

        if (str_ends_with($normalized, 'vbaproject.bin')) {
            $this->fail(SourceFileErrorCode::MacrosNotAllowed, 'Macro-enabled workbooks are not accepted.');
        }

        if (in_array($extension, self::EXECUTABLE_EXTENSIONS, true)
            || str_starts_with($normalized, 'xl/embeddings/')
            || str_starts_with($normalized, 'xl/activex/')
        ) {
            $this->fail(SourceFileErrorCode::EmbeddedExecutable, 'Embedded executable content is not accepted.');
        }
    }

    private function entryContains(ZipArchive $zip, string $entry, string $needle): bool
    {
        $stream = $zip->getStream($entry);

        if ($stream === false) {
            $this->fail(SourceFileErrorCode::InvalidZip, 'The XLSX archive contains an unreadable declaration.');
        }

        $overlap = '';

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 8192);

                if ($chunk === false) {
                    $this->fail(SourceFileErrorCode::InvalidZip, 'The XLSX archive cannot be read safely.');
                }

                $haystack = $overlap.$chunk;

                if (stripos($haystack, $needle) !== false) {
                    return true;
                }

                $overlap = substr($haystack, -max(0, strlen($needle) - 1));
            }
        } finally {
            fclose($stream);
        }

        return false;
    }

    private function fail(SourceFileErrorCode $code, string $message): never
    {
        throw new XlsxValidationException($code, $message);
    }
}
