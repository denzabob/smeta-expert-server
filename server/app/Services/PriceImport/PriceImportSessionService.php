<?php

namespace App\Services\PriceImport;

use App\Models\PriceImportSession;
use App\Models\PriceList;
use App\Models\PriceListVersion;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Сервис управления сессиями импорта прайсов
 * 
 * ВАЖНО (snapshot-prices architecture):
 * - Поставщик (supplier_id) обязателен для импорта
 * - Версия прайс-листа (price_list_version_id) обязательна
 * - Импорт НЕ обновляет базовые цены в materials/operations
 */
class PriceImportSessionService
{
    private PriceFileParser $parser;
    private CandidateMatchingService $matchingService;
    private PriceImportExecutorV2 $executor;

    public function __construct(
        PriceFileParser $parser,
        CandidateMatchingService $matchingService,
        PriceImportExecutorV2 $executor
    ) {
        $this->parser = $parser;
        $this->matchingService = $matchingService;
        $this->executor = $executor;
    }

    /**
     * Create a new import session reusing parsed data from an existing completed session.
     * This allows users to re-run mapping/linking without re-uploading the same file.
     */
    public function createFromExistingSession(
        PriceImportSession $existingSession,
        User $user,
        string $targetType,
        int $supplierId,
        int $priceListId,
        array $options = []
    ): PriceImportSession {
        if ($existingSession->user_id !== $user->id) {
            throw new \InvalidArgumentException('Нельзя использовать чужую сессию импорта');
        }

        if ($existingSession->target_type !== $targetType) {
            throw new \InvalidArgumentException('Тип импорта не совпадает с исходной сессией');
        }

        if ((int) ($existingSession->supplier_id ?? 0) !== (int) $supplierId) {
            throw new \InvalidArgumentException('Поставщик должен совпадать с исходной сессией');
        }

        $priceList = PriceList::findOrFail($priceListId);
        if ((int) $priceList->supplier_id !== (int) $supplierId) {
            throw new \InvalidArgumentException('Прайс-лист не принадлежит выбранному поставщику');
        }

        $priceListVersion = $this->getOrCreateDraftVersion($priceList);
        $rawRows = $existingSession->raw_rows ?? [];

        if (empty($rawRows) && $existingSession->file_path) {
            // Best-effort fallback if old session lost parsed rows.
            $parsed = $this->parser->parse($existingSession, false);
            $rawRows = $parsed['rows'] ?? [];
        }

        if (empty($rawRows)) {
            throw new \InvalidArgumentException('Не удалось восстановить данные исходной сессии импорта');
        }

        return PriceImportSession::create([
            'user_id' => $user->id,
            'price_list_version_id' => $priceListVersion->id,
            'supplier_id' => $supplierId,
            'target_type' => $targetType,
            'file_path' => $existingSession->file_path,
            'storage_disk' => $existingSession->storage_disk ?? 'local',
            'original_filename' => $existingSession->original_filename,
            'file_type' => $existingSession->file_type ?? PriceImportSession::FILE_TYPE_PASTE,
            'file_hash' => $existingSession->file_hash,
            'status' => PriceImportSession::STATUS_MAPPING_REQUIRED,
            'header_row_index' => $options['header_row_index'] ?? ($existingSession->header_row_index ?? 0),
            'sheet_index' => $options['sheet_index'] ?? ($existingSession->sheet_index ?? 0),
            // Start from clean mapping/resolution to let user re-associate.
            'column_mapping' => null,
            'raw_rows' => $rawRows,
            'resolution_queue' => null,
            'stats' => null,
            'options' => array_filter([
                'reused_from_session_id' => $existingSession->id,
                'csv_encoding' => $options['csv_encoding'] ?? ($existingSession->options['csv_encoding'] ?? 'UTF-8'),
                'csv_delimiter' => $options['csv_delimiter'] ?? ($existingSession->options['csv_delimiter'] ?? ','),
            ]),
        ]);
    }

    /**
     * Create session from file upload.
     */
    public function createFromUpload(
        UploadedFile $file,
        User $user,
        string $targetType,
        ?int $supplierId = null,
        ?int $priceListId = null,
        array $options = []
    ): PriceImportSession {
        // Validate target type
        if (!in_array($targetType, [PriceImportSession::TARGET_OPERATIONS, PriceImportSession::TARGET_MATERIALS])) {
            throw new \InvalidArgumentException("Invalid target type: {$targetType}");
        }

        // Calculate file hash for duplicate detection
        $fileContent = file_get_contents($file->getRealPath());
        $fileHash = hash('sha256', $fileContent);

        // Check for duplicate import (same file for same supplier)
        $existingSession = $this->findDuplicateSession($fileHash, $supplierId, $targetType);
        if ($existingSession) {
            throw new DuplicateImportException(
                'Этот файл уже был импортирован ранее.',
                $existingSession
            );
        }

        // Store file
        $filename = $file->getClientOriginalName();
        $fileType = PriceFileParser::detectFileType($filename);
        $storagePath = "price_imports/{$user->id}/" . Str::uuid() . '.' . $file->getClientOriginalExtension();
        
        Storage::put($storagePath, $fileContent);

        // Get or create price list version
        $priceListVersion = null;
        if ($priceListId) {
            $priceList = PriceList::findOrFail($priceListId);
            $priceListVersion = $this->getOrCreateDraftVersion($priceList);
        }

        // Create session
        $session = PriceImportSession::create([
            'user_id' => $user->id,
            'price_list_version_id' => $priceListVersion?->id,
            'supplier_id' => $supplierId,
            'target_type' => $targetType,
            'file_path' => $storagePath,
            'storage_disk' => 'local',
            'original_filename' => $filename,
            'file_type' => $fileType,
            'file_hash' => $fileHash,
            'status' => PriceImportSession::STATUS_CREATED,
            'header_row_index' => $options['header_row_index'] ?? 0,
            'sheet_index' => $options['sheet_index'] ?? 0,
            'options' => array_filter([
                'csv_encoding' => $options['csv_encoding'] ?? 'UTF-8',
                'csv_delimiter' => $options['csv_delimiter'] ?? ',',
            ]),
        ]);

        // Parse full file immediately and store complete raw rows.
        // Preview in UI is produced separately from stored rows.
        try {
            $parsed = $this->parser->parse($session, true);
            $session->raw_rows = $parsed['rows'];
            $session->status = PriceImportSession::STATUS_MAPPING_REQUIRED;
            $session->save();
        } catch (ParsingException $e) {
            $session->markParsingFailed($e->getMessage(), $e->getDetails());
        }

        return $session;
    }

    /**
     * Find duplicate import session by file hash.
     */
    protected function findDuplicateSession(
        string $fileHash,
        ?int $supplierId,
        string $targetType
    ): ?PriceImportSession {
        return PriceImportSession::where('file_hash', $fileHash)
            ->where('target_type', $targetType)
            ->when($supplierId, function ($query, $supplierId) {
                $query->where('supplier_id', $supplierId);
            })
            ->where('status', PriceImportSession::STATUS_COMPLETED)
            ->orderBy('created_at', 'desc')
            ->first();
    }

    /**
     * Create session from pasted content.
     */
    public function createFromPaste(
        string $content,
        User $user,
        string $targetType,
        ?int $supplierId = null,
        ?int $priceListId = null,
        array $options = []
    ): PriceImportSession {
        // Validate target type
        if (!in_array($targetType, [PriceImportSession::TARGET_OPERATIONS, PriceImportSession::TARGET_MATERIALS])) {
            throw new \InvalidArgumentException("Invalid target type: {$targetType}");
        }

        // Detect content type
        $fileType = str_contains($content, '<table') || str_contains($content, '<TABLE')
            ? PriceImportSession::FILE_TYPE_HTML
            : PriceImportSession::FILE_TYPE_PASTE;

        // Get or create price list version
        $priceListVersion = null;
        if ($priceListId) {
            $priceList = PriceList::findOrFail($priceListId);
            $priceListVersion = $this->getOrCreateDraftVersion($priceList);
        }

        // Create session
        $session = PriceImportSession::create([
            'user_id' => $user->id,
            'price_list_version_id' => $priceListVersion?->id,
            'supplier_id' => $supplierId,
            'target_type' => $targetType,
            'file_type' => $fileType,
            'status' => PriceImportSession::STATUS_CREATED,
            'header_row_index' => $options['header_row_index'] ?? 0,
            'options' => array_filter([
                'csv_delimiter' => $options['csv_delimiter'] ?? "\t", // Tab for pasted data
            ]),
        ]);

        // Try to parse content
        try {
            $parsed = $this->parser->parsePaste($content, $session);
            $session->raw_rows = $parsed['rows'];
            $session->status = PriceImportSession::STATUS_MAPPING_REQUIRED;
            $session->save();
        } catch (ParsingException $e) {
            $session->markParsingFailed($e->getMessage(), $e->getDetails());
        }

        return $session;
    }

    /**
     * Get preview data for session.
     */
    public function getPreview(PriceImportSession $session, int $maxRows = 50): array
    {
        $rows = $session->raw_rows ?? [];
        $headerRowIndex = $session->header_row_index ?? 0;

        // Get headers
        $headers = $rows[$headerRowIndex] ?? [];

        // Get sample rows (skip header)
        $sampleRows = array_slice($rows, $headerRowIndex + 1, $maxRows);

        // Detect column types
        $columnTypes = $this->detectColumnTypes($rows, $headerRowIndex);

        return [
            'session_id' => $session->id,
            'status' => $session->status,
            'original_filename' => $session->original_filename,
            'file_type' => $session->file_type,
            'total_rows' => count($rows),
            'header_row_index' => $headerRowIndex,
            'headers' => $headers,
            'sample_rows' => $sampleRows,
            'column_count' => count($headers),
            'column_types' => $columnTypes,
            'current_mapping' => $session->column_mapping,
        ];
    }

    /**
     * Re-parse file when sheet changes.
     */
    public function reparseFile(PriceImportSession $session): void
    {
        if (!$session->file_path) {
            return;
        }

        try {
            $parsed = $this->parser->parse($session, false);
            $session->raw_rows = $parsed['rows'];
            $session->status = PriceImportSession::STATUS_MAPPING_REQUIRED;
            $session->column_mapping = null; // Reset mapping for new sheet
            $session->save();
        } catch (ParsingException $e) {
            $session->markParsingFailed($e->getMessage(), $e->getDetails());
        }
    }

    /**
     * Save column mapping and trigger dry run.
     */
    public function saveMapping(PriceImportSession $session, array $mapping): array
    {
        if (!$session->canApplyMapping()) {
            throw new \InvalidArgumentException("Cannot apply mapping in status '{$session->status}'");
        }

        // Validate mapping
        $this->validateMapping($mapping, $session->target_type);

        // Save mapping
        $session->column_mapping = $mapping;
        $session->save();

        // Run dry run
        return $this->runDryRun($session);
    }

    /**
     * Run dry run (matching without writing).
     */
    public function runDryRun(PriceImportSession $session): array
    {
        if (!$session->column_mapping) {
            throw new \InvalidArgumentException('Session has no column mapping');
        }

        // Parse full file for uploaded sessions before matching when source file exists.
        // If file was deleted (e.g. cancelled old session/reuse flow), fallback to already
        // stored raw_rows to avoid "File not found" hard failure.
        $hasFilePath = !empty($session->file_path);
        $storageDisk = $session->storage_disk ?? 'local';
        $fileExists = $hasFilePath && Storage::disk($storageDisk)->exists($session->file_path);

        if ($fileExists) {
            try {
                $parsed = $this->parser->parse($session, true);
                $session->raw_rows = $parsed['rows'];
                $session->save();
            } catch (ParsingException $e) {
                $session->markParsingFailed($e->getMessage(), $e->getDetails());
                throw $e;
            }
        } elseif ($hasFilePath) {
            $rowCount = count($session->raw_rows ?? []);
            $header = (int) ($session->header_row_index ?? 0);
            // Heuristic: preview-limited sessions often have around header + 100 rows.
            if ($rowCount > 0 && $rowCount <= ($header + 105)) {
                throw new \InvalidArgumentException(
                    'Исходный файл недоступен, а сохранён только предварительный фрагмент данных. ' .
                    'Загрузите файл повторно для полного импорта (без лимита preview).'
                );
            }
        }

        // Run matching
        $result = $this->matchingService->dryRun($session);

        // Update session
        $session->stats = $result['stats'];
        $session->resolution_queue = $result['resolution_queue'];
        $session->status = PriceImportSession::STATUS_RESOLUTION_REQUIRED;
        $session->save();

        return $result;
    }

    /**
     * Get resolution queue (items needing manual decision).
     */
    public function getResolutionQueue(PriceImportSession $session, ?string $filterStatus = null): array
    {
        $queue = $session->resolution_queue ?? [];

        if ($filterStatus) {
            $queue = array_filter($queue, fn($item) => $item['status'] === $filterStatus);
        }

        return [
            'session_id' => $session->id,
            'stats' => $session->stats,
            'resolution_queue' => array_values($queue),
        ];
    }

    /**
     * Apply bulk action to resolution queue.
     */
    public function applyBulkAction(PriceImportSession $session, string $action, array $rowIndexes, array $params = []): array
    {
        $queue = $session->resolution_queue ?? [];
        $updated = 0;

        foreach ($queue as &$item) {
            if (!in_array($item['row_index'], $rowIndexes)) {
                continue;
            }

            switch ($action) {
                case 'accept_as_new':
                    $item['decision'] = [
                        'action' => 'create',
                        'conversion_factor' => $params['conversion_factor'] ?? 1.0,
                        'supplier_unit' => $params['supplier_unit'] ?? $item['raw_data']['unit'] ?? null,
                        'internal_unit' => $params['internal_unit'] ?? null,
                    ];
                    break;

                case 'ignore':
                    $item['decision'] = ['action' => 'ignore'];
                    $item['status'] = 'ignored';
                    break;

                case 'link':
                    if (empty($params['internal_item_id'])) {
                        continue 2;
                    }
                    $item['decision'] = [
                        'action' => 'link',
                        'internal_item_id' => $params['internal_item_id'],
                        'conversion_factor' => $params['conversion_factor'] ?? 1.0,
                        'supplier_unit' => $params['supplier_unit'] ?? null,
                        'internal_unit' => $params['internal_unit'] ?? null,
                    ];
                    break;

                case 'set_conversion':
                    $item['decision'] = array_merge($item['decision'] ?? [], [
                        'conversion_factor' => $params['conversion_factor'] ?? 1.0,
                        'supplier_unit' => $params['supplier_unit'] ?? null,
                        'internal_unit' => $params['internal_unit'] ?? null,
                    ]);
                    break;
            }

            $updated++;
        }

        // Update stats
        $stats = $this->recalculateStats($queue);

        $session->resolution_queue = $queue;
        $session->stats = $stats;
        $session->save();

        return [
            'updated' => $updated,
            'stats' => $stats,
        ];
    }

    /**
     * Execute import with provided decisions.
     */
    public function execute(PriceImportSession $session, array $decisions = []): array
    {
        return $this->executor->execute($session, $decisions);
    }

    /**
     * Get or create inactive price list version.
     * 
     * По ТЗ: импорт всегда создает inactive версию.
     * После завершения импорта пользователь может активировать ее вручную.
     */
    private function getOrCreateDraftVersion(PriceList $priceList): PriceListVersion
    {
        // Check for existing inactive draft
        $inactive = $priceList->versions()
            ->where('status', PriceListVersion::STATUS_INACTIVE)
            ->whereNull('effective_date') // Draft versions don't have effective date yet
            ->first();

        if ($inactive) {
            return $inactive;
        }

        // Create new inactive version
        return PriceListVersion::create([
            'price_list_id' => $priceList->id,
            'version_number' => $priceList->getNextVersionNumber(),
            'currency' => $priceList->default_currency,
            'status' => PriceListVersion::STATUS_INACTIVE,
            'source_type' => PriceListVersion::SOURCE_FILE,
            'captured_at' => now(),
        ]);
    }

    /**
     * Validate column mapping.
     */
    private function validateMapping(array $mapping, string $targetType): void
    {
        $requiredFields = $targetType === 'operations'
            ? ['name', 'cost_per_unit']
            : ['name', 'price'];

        $mappedFields = array_values($mapping);

        foreach ($requiredFields as $field) {
            if (!in_array($field, $mappedFields)) {
                throw new \InvalidArgumentException("Required field '{$field}' is not mapped");
            }
        }
    }

    /**
     * Detect column types from data.
     */
    private function detectColumnTypes(array $rows, int $headerRowIndex): array
    {
        if (count($rows) <= $headerRowIndex + 1) {
            return [];
        }

        $sampleRows = array_slice($rows, $headerRowIndex + 1, 10);
        $columnCount = count($rows[$headerRowIndex] ?? []);
        $types = [];

        for ($col = 0; $col < $columnCount; $col++) {
            $values = array_map(fn($row) => $row[$col] ?? null, $sampleRows);
            $types[$col] = $this->detectColumnType($values);
        }

        return $types;
    }

    /**
     * Detect single column type.
     */
    private function detectColumnType(array $values): string
    {
        $values = array_filter($values, fn($v) => $v !== null && $v !== '');
        
        if (empty($values)) {
            return 'unknown';
        }

        $numericCount = 0;
        $textCount = 0;

        foreach ($values as $value) {
            $parsed = TextNormalizer::extractPrice((string) $value);
            if ($parsed !== null) {
                $numericCount++;
            } else {
                $textCount++;
            }
        }

        $total = count($values);
        
        if ($numericCount / $total > 0.7) {
            return 'numeric';
        }

        return 'text';
    }

    /**
     * Recalculate stats from queue.
     */
    private function recalculateStats(array $queue): array
    {
        $stats = [
            'total' => 0,
            'auto_matched' => 0,
            'ambiguous' => 0,
            'new' => 0,
            'ignored' => 0,
        ];

        foreach ($queue as $item) {
            $stats['total']++;
            $status = $item['decision']['action'] ?? $item['status'] ?? 'unknown';
            
            if ($status === 'ignore' || $item['status'] === 'ignored') {
                $stats['ignored']++;
            } elseif (in_array($item['status'], ['auto_matched'])) {
                $stats['auto_matched']++;
            } elseif ($item['status'] === 'ambiguous') {
                $stats['ambiguous']++;
            } elseif ($item['status'] === 'new') {
                $stats['new']++;
            }
        }

        return $stats;
    }
}
