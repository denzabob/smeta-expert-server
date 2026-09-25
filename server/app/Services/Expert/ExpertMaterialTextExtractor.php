<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Models\Expert\ExpertProjectMaterial;
use DOMDocument;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use Smalot\PdfParser\Parser;
use ZipArchive;

final class ExpertMaterialTextExtractor implements ExpertMaterialTextExtractorInterface
{
    public function __construct(
        private readonly ExpertStorageService $storage,
        private readonly ?Parser $pdfParser = null,
    ) {}

    public function supports(ExpertProjectMaterial $material): bool
    {
        $extension = $this->extension($material);
        $mimeType = strtolower((string) $material->mime_type);

        if (str_starts_with($mimeType, 'image/') || in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return false;
        }

        return in_array($extension, ['txt', 'md', 'docx', 'xlsx', 'pdf'], true)
            || str_starts_with($mimeType, 'text/');
    }

    public function extract(ExpertProjectMaterial $material, string $contents): string
    {
        $extension = $this->extension($material);

        if (! $this->supports($material)) {
            throw ExpertMaterialContextException::unsupported();
        }

        try {
            return match ($extension) {
                'docx' => $this->storage->withTemporaryFileFromContents(
                    $contents,
                    fn (string $path): string => $this->extractDocx($path, $contents),
                    $this->storage->contextForMaterial($material),
                ),
                'xlsx' => $this->storage->withTemporaryFileFromContents(
                    $contents,
                    fn (string $path): string => $this->extractXlsx($path, $contents),
                    $this->storage->contextForMaterial($material),
                ),
                'pdf' => $this->extractPdf($contents),
                default => $this->normaliseText($contents),
            };
        } catch (ExpertMaterialContextException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw ExpertMaterialContextException::extractionFailed();
        }
    }

    private function extractDocx(string $temporaryPath, string $contents): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw ExpertMaterialContextException::extractionFailed();
        }

        $this->assertZipSignature($contents);
        $archive = new ZipArchive;
        if ($archive->open($temporaryPath) !== true) {
            throw ExpertMaterialContextException::extractionFailed();
        }

        try {
            $this->assertArchiveWithinLimits(
                $archive,
                ['[Content_Types].xml', '_rels/.rels', 'word/document.xml'],
                max(1, (int) config('expert.material_context.max_docx_archive_entries', 200)),
                max(1, (int) config('expert.material_context.max_docx_uncompressed_bytes', 20 * 1024 * 1024)),
                max(1, (int) config('expert.material_context.max_docx_xml_entry_bytes', 5 * 1024 * 1024)),
            );
            $documentXml = $archive->getFromName('word/document.xml');
        } finally {
            $archive->close();
        }

        if (! is_string($documentXml) || $documentXml === '') {
            throw ExpertMaterialContextException::extractionFailed();
        }

        $document = $this->loadSafeXml($documentXml);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $bodyItems = $xpath->query('/w:document/w:body/*');

        if ($bodyItems === false) {
            throw ExpertMaterialContextException::extractionFailed();
        }

        $blocks = [];
        foreach ($bodyItems as $item) {
            if ($item->localName === 'p') {
                $paragraph = $this->wordText($xpath, $item);
                if ($paragraph !== '') {
                    $blocks[] = $paragraph;
                }

                continue;
            }

            if ($item->localName !== 'tbl') {
                continue;
            }

            foreach ($xpath->query('./w:tr', $item) ?: [] as $row) {
                $cells = [];
                foreach ($xpath->query('./w:tc', $row) ?: [] as $cell) {
                    $text = $this->wordText($xpath, $cell);
                    if ($text !== '') {
                        $cells[] = $text;
                    }
                }

                if ($cells !== []) {
                    $blocks[] = implode(' | ', $cells);
                }
            }
        }

        return $this->normaliseText(implode("\n", $blocks));
    }

    private function extractXlsx(string $temporaryPath, string $contents): string
    {
        $spreadsheet = null;

        try {
            $this->assertZipSignature($contents);
            $this->assertSpreadsheetArchiveWithinLimit($temporaryPath);

            $reader = new Xlsx;
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($temporaryPath);
            $maxCells = max(1, (int) config('expert.material_context.max_spreadsheet_cells', 5000));
            $cellCount = 0;
            $lines = [];

            foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
                $sheetLines = [];
                foreach ($worksheet->getCoordinates() as $coordinate) {
                    $cell = $worksheet->getCell($coordinate);
                    $value = $cell->getValue();

                    if (is_string($value) && str_starts_with($value, '=')) {
                        $text = '[Формула] '.$value;
                    } else {
                        $text = $this->spreadsheetValue($value);
                    }

                    if ($text === null || trim($text) === '') {
                        continue;
                    }

                    $cellCount++;
                    if ($cellCount > $maxCells) {
                        throw ExpertMaterialContextException::tooLarge();
                    }

                    $sheetLines[] = $coordinate.': '.$text;
                }

                if ($sheetLines !== []) {
                    $lines[] = '[Sheet: '.$worksheet->getTitle().']';
                    array_push($lines, ...$sheetLines);
                }
            }

            return $this->normaliseText(implode("\n", $lines));
        } finally {
            $spreadsheet?->disconnectWorksheets();
        }
    }

    private function assertSpreadsheetArchiveWithinLimit(string $temporaryPath): void
    {
        if (! class_exists(ZipArchive::class)) {
            throw ExpertMaterialContextException::extractionFailed();
        }

        $archive = new ZipArchive;
        if ($archive->open($temporaryPath) !== true) {
            throw ExpertMaterialContextException::extractionFailed();
        }

        try {
            $this->assertArchiveWithinLimits(
                $archive,
                ['[Content_Types].xml', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels'],
                max(1, (int) config('expert.material_context.max_xlsx_archive_entries', 500)),
                max(1, (int) config('expert.material_context.max_spreadsheet_uncompressed_bytes', 20 * 1024 * 1024)),
                max(1, (int) config('expert.material_context.max_xlsx_xml_entry_bytes', 5 * 1024 * 1024)),
            );
        } finally {
            $archive->close();
        }
    }

    private function extractPdf(string $contents): string
    {
        if (! str_starts_with($contents, '%PDF-')) {
            throw ExpertMaterialContextException::pdfMalformed();
        }
        if (preg_match('/\\/Encrypt\\b/', $contents) === 1) {
            throw ExpertMaterialContextException::pdfEncrypted();
        }

        // A missing/unsupported local parser is not proof that the source PDF is corrupt.
        $fallbackPages = preg_match_all('/\/Type\s*\/Page\b/', $contents);
        $hasTrailer = preg_match('/startxref\s+(\d+)\s+%%EOF\s*$/s', $contents, $trailer) === 1;
        $xrefOffset = $hasTrailer ? (int) $trailer[1] : -1;
        $xref = $xrefOffset >= 0 && $xrefOffset < strlen($contents) ? substr($contents, $xrefOffset, 512) : '';
        $hasXref = str_starts_with($xref, 'xref')
            || (preg_match('/^\d+\s+\d+\s+obj\b/', $xref) === 1 && preg_match('/\/Type\s*\/XRef\b/', $xref) === 1);
        $hasStructure = $fallbackPages > 0 && $hasTrailer && $hasXref;

        try {
            $document = ($this->pdfParser ?? new Parser)->parseContent($contents);
            $pageCount = count($document->getPages());
            if ($pageCount === 0) {
                throw ExpertMaterialContextException::pdfMalformed();
            }
        } catch (ExpertMaterialContextException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::warning('Expert PDF local parser failed.', [
                'exception_class' => $exception::class,
                'exception_message' => mb_substr($exception->getMessage(), 0, 300),
            ]);
            throw $hasStructure
                ? ExpertMaterialContextException::pdfLocalExtractionFailed($fallbackPages)
                : ExpertMaterialContextException::pdfMalformed();
        }

        try {
            $text = (string) $document->getText();
            if (! mb_check_encoding($text, 'UTF-8')) {
                throw new \UnexpectedValueException('PDF text is not UTF-8.');
            }
        } catch (\Throwable) {
            throw ExpertMaterialContextException::pdfLocalExtractionFailed($pageCount);
        }
        $meaningfulChars = preg_match_all('/[\p{L}\p{N}]/u', $text) ?: 0;
        if ($meaningfulChars < max(1, (int) config('expert.pdf_ocr.min_usable_text_chars', 16))) {
            throw ExpertMaterialContextException::pdfWithoutUsableText($pageCount);
        }

        try {
            return $this->normaliseText($text);
        } catch (\Throwable) {
            throw ExpertMaterialContextException::pdfLocalExtractionFailed($pageCount);
        }
    }

    private function wordText(DOMXPath $xpath, DOMNode $node): string
    {
        $parts = [];

        foreach ($xpath->query('.//w:t', $node) ?: [] as $textNode) {
            $parts[] = $textNode->textContent;
        }

        return trim(implode('', $parts));
    }

    private function spreadsheetValue(mixed $value): ?string
    {
        if ($value instanceof RichText) {
            return $value->getPlainText();
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }

    private function normaliseText(string $text): string
    {
        if (str_contains($text, "\0") || ! mb_check_encoding($text, 'UTF-8')) {
            throw ExpertMaterialContextException::extractionFailed();
        }

        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[\x01-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;

        if (class_exists(\Normalizer::class)) {
            $normalised = \Normalizer::normalize($text, \Normalizer::FORM_C);
            if ($normalised === false) {
                throw ExpertMaterialContextException::extractionFailed();
            }
            $text = $normalised;
        }

        $text = trim($text);
        if ($text === '') {
            throw ExpertMaterialContextException::extractionFailed();
        }

        return $text;
    }

    /**
     * @param  list<string>  $requiredEntries
     */
    private function assertArchiveWithinLimits(
        ZipArchive $archive,
        array $requiredEntries,
        int $maxEntries,
        int $maxUncompressedBytes,
        int $maxXmlEntryBytes,
    ): void {
        if ($archive->numFiles > $maxEntries) {
            throw ExpertMaterialContextException::tooLarge();
        }

        $maxCompressionRatio = max(1, (int) config('expert.material_context.max_zip_compression_ratio', 100));
        $uncompressedBytes = 0;

        for ($index = 0; $index < $archive->numFiles; $index++) {
            $entry = $archive->statIndex($index);
            if (! is_array($entry) || ! isset($entry['name'])) {
                throw ExpertMaterialContextException::extractionFailed();
            }

            $name = (string) $entry['name'];
            $size = max(0, (int) ($entry['size'] ?? 0));
            $compressedSize = (int) ($entry['comp_size'] ?? -1);

            if ($name === '') {
                throw ExpertMaterialContextException::extractionFailed();
            }

            if (str_ends_with(strtolower($name), '.xml') && $size > $maxXmlEntryBytes) {
                throw ExpertMaterialContextException::tooLarge();
            }

            if ($size > 0 && ($compressedSize <= 0 || $size / $compressedSize > $maxCompressionRatio)) {
                throw ExpertMaterialContextException::tooLarge();
            }

            $uncompressedBytes += $size;
            if ($uncompressedBytes > $maxUncompressedBytes) {
                throw ExpertMaterialContextException::tooLarge();
            }
        }

        foreach ($requiredEntries as $entryName) {
            if (! is_array($archive->statName($entryName))) {
                throw ExpertMaterialContextException::extractionFailed();
            }
        }
    }

    private function assertZipSignature(string $contents): void
    {
        if (substr($contents, 0, 4) !== "PK\x03\x04") {
            throw ExpertMaterialContextException::extractionFailed();
        }
    }

    private function loadSafeXml(string $xml): DOMDocument
    {
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw ExpertMaterialContextException::extractionFailed();
        }

        $previousErrors = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument;
            $document->resolveExternals = false;
            $document->substituteEntities = false;

            if (! $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
                throw ExpertMaterialContextException::extractionFailed();
            }

            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
    }

    private function extension(ExpertProjectMaterial $material): string
    {
        return strtolower(ltrim(trim((string) $material->extension), '.'));
    }
}
