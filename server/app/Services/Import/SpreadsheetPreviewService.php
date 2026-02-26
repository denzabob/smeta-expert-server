<?php

namespace App\Services\Import;

use App\Models\ImportSession;
use App\Utilities\SpreadsheetReader;

/**
 * Service for getting preview data from spreadsheets.
 */
class SpreadsheetPreviewService
{
    public function __construct(
        private ImportSessionService $sessionService
    ) {}

    /**
     * Get metadata about the file (sheets, column count).
     *
     * @param ImportSession $session The import session
     * @return array{sheets: array, column_count: int}
     */
    public function getMetadata(ImportSession $session): array
    {
        $reader = $this->sessionService->getReader($session);
        return $reader->getMetadata();
    }

    /**
     * Get a preview of the file data.
     *
     * @param ImportSession $session The import session
     * @param int|null $sheetIndex Optional sheet index override
     * @param int|null $headerRowIndex Optional header row index override
     * @param int $maxRows Maximum rows to return
     * @return array{columns: array, rows: array, header_row_index: int, sheet_index: int}
     */
    public function getPreview(
        ImportSession $session,
        ?int $sheetIndex = null,
        ?int $headerRowIndex = null,
        int $maxRows = 20
    ): array {
        $sheetIndex = $sheetIndex ?? $session->sheet_index;
        $headerRowIndex = $headerRowIndex ?? $session->header_row_index;

        $reader = $this->sessionService->getReader($session);
        $previewData = $reader->readPreview($maxRows + $headerRowIndex + 5, $sheetIndex);

        // Get columns from header row (or fallback to column metadata)
        $columns = [];
        if ($headerRowIndex >= 0 && isset($previewData['rows'][$headerRowIndex])) {
            $headerRow = $previewData['rows'][$headerRowIndex];
            $columns = array_map(function ($index, $value) {
                return [
                    'index' => $index,
                    'name_guess' => is_string($value) ? trim($value) : (string) $value,
                ];
            }, array_keys($headerRow), $headerRow);
        } else {
            // Use initial column info if header row doesn't exist
            $columns = $previewData['columns'];
        }

        // Get data rows (starting after header or from first row if no header)
        $dataStartIndex = $headerRowIndex >= 0 ? $headerRowIndex + 1 : 0;
        $dataRows = array_slice($previewData['rows'], $dataStartIndex, $maxRows);

        // Include the original row indices for reference
        $rowsWithIndices = array_map(function ($row, $dataIndex) use ($dataStartIndex) {
            return [
                'original_index' => $dataStartIndex + $dataIndex, // 0-based
                'cells' => $row,
            ];
        }, $dataRows, array_keys($dataRows));

        return [
            'columns' => $columns,
            'rows' => $rowsWithIndices,
            'header_row_index' => $headerRowIndex,
            'sheet_index' => $sheetIndex,
            'total_preview_rows' => count($rowsWithIndices),
        ];
    }

    /**
     * Get the full response data for the upload/preview endpoint.
     *
     * @param ImportSession $session The import session
     * @param int|null $sheetIndex Optional sheet index override
     * @param int|null $headerRowIndex Optional header row index override
     * @return array
     */
    public function getFullPreviewResponse(
        ImportSession $session,
        ?int $sheetIndex = null,
        ?int $headerRowIndex = null
    ): array {
        $metadata = $this->getMetadata($session);
        $preview = $this->getPreview($session, $sheetIndex, $headerRowIndex);

        return [
            'import_session_id' => $session->id,
            'file_info' => [
                'original_filename' => $session->original_filename,
                'file_type' => $session->file_type,
            ],
            'meta' => [
                'sheets' => $metadata['sheets'],
                'sheets_count' => count($metadata['sheets']),
                'column_count' => count($preview['columns']),
            ],
            'preview' => $preview,
            'options' => $session->options,
        ];
    }
}
