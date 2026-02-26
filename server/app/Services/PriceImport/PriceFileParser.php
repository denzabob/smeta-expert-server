<?php

namespace App\Services\PriceImport;

use App\Models\PriceImportSession;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Парсер файлов прайсов с fail-fast логикой
 */
class PriceFileParser
{
    private const MAX_ROWS_PREVIEW = 100;
    private const MAX_ROWS_FULL = 50000;

    /**
     * Parse uploaded file and return rows.
     * 
     * @throws ParsingException
     */
    public function parse(PriceImportSession $session, bool $fullParse = false): array
    {
        $path = $session->getStoragePath();
        
        if (!$path || !file_exists($path)) {
            throw new ParsingException('File not found');
        }

        return match ($session->file_type) {
            PriceImportSession::FILE_TYPE_XLSX,
            PriceImportSession::FILE_TYPE_XLS => $this->parseExcel($path, $session, $fullParse),
            PriceImportSession::FILE_TYPE_CSV => $this->parseCsv($path, $session, $fullParse),
            PriceImportSession::FILE_TYPE_HTML => $this->parseHtml($path, $session, $fullParse),
            default => throw new ParsingException("Unsupported file type: {$session->file_type}"),
        };
    }

    /**
     * Parse pasted content (HTML table or CSV).
     * 
     * @throws ParsingException
     */
    public function parsePaste(string $content, PriceImportSession $session): array
    {
        // Detect if HTML
        if (str_contains($content, '<table') || str_contains($content, '<TABLE')) {
            return $this->parseHtmlContent($content, $session);
        }

        // Treat as CSV
        return $this->parseCsvContent($content, $session);
    }

    /**
     * Parse Excel file.
     */
    private function parseExcel(string $path, PriceImportSession $session, bool $fullParse): array
    {
        try {
            $spreadsheet = IOFactory::load($path);
            $sheetIndex = $session->sheet_index ?? 0;
            
            $sheetCount = $spreadsheet->getSheetCount();
            if ($sheetIndex >= $sheetCount) {
                throw new ParsingException("Sheet index {$sheetIndex} not found. File has {$sheetCount} sheets.");
            }

            $sheet = $spreadsheet->getSheet($sheetIndex);
            $highestRow = $sheet->getHighestRow();
            $highestColumn = $sheet->getHighestColumn();
            $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

            $headerRowIndex = $session->header_row_index ?? 0;
            $maxRows = $fullParse ? self::MAX_ROWS_FULL : self::MAX_ROWS_PREVIEW;
            $endRow = min($highestRow, $headerRowIndex + $maxRows + 1);

            $rows = [];
            $headers = [];

            for ($row = 1; $row <= $endRow; $row++) {
                $rowData = [];
                for ($col = 1; $col <= $highestColumnIndex; $col++) {
                    $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                    $cell = $sheet->getCell($colLetter . $row);
                    $rowData[] = $this->getCellValue($cell);
                }

                // Store header row
                if ($row === $headerRowIndex + 1) {
                    $headers = $rowData;
                }

                $rows[] = $rowData;
            }

            return [
                'headers' => $headers,
                'rows' => $rows,
                'total_rows' => $highestRow,
                'column_count' => $highestColumnIndex,
                'sheet_count' => $sheetCount,
                'sheet_names' => array_map(fn($i) => $spreadsheet->getSheet($i)->getTitle(), range(0, $sheetCount - 1)),
            ];
        } catch (ParsingException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ParsingException("Failed to parse Excel file: {$e->getMessage()}");
        }
    }

    /**
     * Get cell value handling different types.
     */
    private function getCellValue($cell): mixed
    {
        $value = $cell->getValue();
        
        if ($value instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
            return $value->getPlainText();
        }

        // Try to get calculated value for formulas
        try {
            $calculated = $cell->getCalculatedValue();
            if ($calculated !== null) {
                return $calculated;
            }
        } catch (\Exception $e) {
            // Ignore calculation errors
        }

        return $value;
    }

    /**
     * Parse CSV file.
     */
    private function parseCsv(string $path, PriceImportSession $session, bool $fullParse): array
    {
        $encoding = $session->getOption('csv_encoding', 'UTF-8');
        $delimiter = $session->getOption('csv_delimiter', ',');
        
        $content = file_get_contents($path);
        
        // Convert encoding if needed
        if ($encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }

        return $this->parseCsvContent($content, $session, $fullParse);
    }

    /**
     * Parse CSV content string.
     */
    private function parseCsvContent(string $content, PriceImportSession $session, bool $fullParse = true): array
    {
        $delimiter = $session->getOption('csv_delimiter', ',');
        $headerRowIndex = $session->header_row_index ?? 0;
        $maxRows = $fullParse ? self::MAX_ROWS_FULL : self::MAX_ROWS_PREVIEW;

        // Normalize line endings
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $lines = explode("\n", $content);

        $rows = [];
        $headers = [];
        $maxColumns = 0;
        $rowCount = 0;

        foreach ($lines as $index => $line) {
            if ($rowCount >= $maxRows + $headerRowIndex + 1) {
                break;
            }

            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $rowData = str_getcsv($line, $delimiter);
            $maxColumns = max($maxColumns, count($rowData));

            if ($index === $headerRowIndex) {
                $headers = $rowData;
            }

            $rows[] = $rowData;
            $rowCount++;
        }

        // Normalize row lengths
        foreach ($rows as &$row) {
            while (count($row) < $maxColumns) {
                $row[] = null;
            }
        }

        return [
            'headers' => $headers,
            'rows' => $rows,
            'total_rows' => count($lines),
            'column_count' => $maxColumns,
            'sheet_count' => 1,
            'sheet_names' => ['Sheet1'],
        ];
    }

    /**
     * Parse HTML file.
     */
    private function parseHtml(string $path, PriceImportSession $session, bool $fullParse): array
    {
        $content = file_get_contents($path);
        return $this->parseHtmlContent($content, $session, $fullParse);
    }

    /**
     * Parse HTML content (table).
     * 
     * @throws ParsingException
     */
    private function parseHtmlContent(string $content, PriceImportSession $session, bool $fullParse = true): array
    {
        // Find table
        if (!preg_match('/<table[^>]*>(.*?)<\/table>/is', $content, $matches)) {
            throw new ParsingException('No table found in HTML content');
        }

        $tableContent = $matches[1];

        // Check for rowspan/colspan - fail fast for complex tables
        if (preg_match('/\b(rowspan|colspan)\s*=/i', $tableContent)) {
            throw new ParsingException(
                'Complex table structure detected (rowspan/colspan). ' .
                'Please copy data as CSV/Excel format instead.'
            );
        }

        // Parse rows
        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $tableContent, $rowMatches);
        
        if (empty($rowMatches[1])) {
            throw new ParsingException('No rows found in table');
        }

        $rows = [];
        $maxColumns = 0;
        $headerRowIndex = $session->header_row_index ?? 0;
        $maxRows = $fullParse ? self::MAX_ROWS_FULL : self::MAX_ROWS_PREVIEW;

        foreach ($rowMatches[1] as $index => $rowHtml) {
            if ($index >= $maxRows + $headerRowIndex + 1) {
                break;
            }

            // Parse cells (th and td)
            preg_match_all('/<t[hd][^>]*>(.*?)<\/t[hd]>/is', $rowHtml, $cellMatches);
            
            $rowData = array_map(function($cell) {
                // Strip HTML tags and decode entities
                $text = strip_tags($cell);
                $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                return trim($text);
            }, $cellMatches[1]);

            $maxColumns = max($maxColumns, count($rowData));
            $rows[] = $rowData;
        }

        // Normalize row lengths
        foreach ($rows as &$row) {
            while (count($row) < $maxColumns) {
                $row[] = null;
            }
        }

        $headers = isset($rows[$headerRowIndex]) ? $rows[$headerRowIndex] : [];

        return [
            'headers' => $headers,
            'rows' => $rows,
            'total_rows' => count($rowMatches[1]),
            'column_count' => $maxColumns,
            'sheet_count' => 1,
            'sheet_names' => ['Table1'],
        ];
    }

    /**
     * Get sheet list from Excel file.
     */
    public function getSheetList(string $path): array
    {
        try {
            $spreadsheet = IOFactory::load($path);
            $sheetCount = $spreadsheet->getSheetCount();
            
            return array_map(function($i) use ($spreadsheet) {
                $sheet = $spreadsheet->getSheet($i);
                return [
                    'index' => $i,
                    'name' => $sheet->getTitle(),
                    'row_count' => $sheet->getHighestRow(),
                ];
            }, range(0, $sheetCount - 1));
        } catch (\Exception $e) {
            throw new ParsingException("Failed to read sheet list: {$e->getMessage()}");
        }
    }

    /**
     * Detect file type from content.
     */
    public static function detectFileType(string $filename, ?string $content = null): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        return match ($extension) {
            'xlsx' => PriceImportSession::FILE_TYPE_XLSX,
            'xls' => PriceImportSession::FILE_TYPE_XLS,
            'csv' => PriceImportSession::FILE_TYPE_CSV,
            'html', 'htm' => PriceImportSession::FILE_TYPE_HTML,
            default => self::detectFromContent($content),
        };
    }

    /**
     * Detect file type from content.
     */
    private static function detectFromContent(?string $content): string
    {
        if (!$content) {
            return PriceImportSession::FILE_TYPE_CSV;
        }

        if (str_contains($content, '<table') || str_contains($content, '<TABLE')) {
            return PriceImportSession::FILE_TYPE_HTML;
        }

        return PriceImportSession::FILE_TYPE_CSV;
    }
}
