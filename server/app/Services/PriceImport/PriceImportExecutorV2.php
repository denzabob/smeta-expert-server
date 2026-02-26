<?php

namespace App\Services\PriceImport;

use App\Models\Material;
use App\Models\MaterialPrice;
use App\Models\Operation;
use App\Models\OperationPrice;
use App\Models\PriceImportSession;
use App\Models\PriceListVersion;
use App\Models\SupplierProductAlias;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Сервис выполнения импорта (snapshot-prices architecture V2)
 * 
 * ВАЖНО: Импорт НИКОГДА не обновляет базовые цены в materials/operations.
 * Все цены сохраняются как "снимок" по версии прайса и поставщику.
 * 
 * Для операций: цены пишутся в operation_prices с привязкой к supplier_id
 * Для материалов: создаются записи в material_prices с supplier_id
 * 
 */
class PriceImportExecutorV2
{
    public function __construct()
    {
    }
    /**
     * Execute import based on resolved decisions.
     * 
     * @param PriceImportSession $session
     * @param array $decisions User decisions for resolution queue items
     * @return array Result stats
     * @throws \Exception
     */
    public function execute(PriceImportSession $session, array $decisions): array
    {
        // Валидация: supplier и price_list_version обязательны
        $this->validateRequirements($session);

        if (!$session->canExecute()) {
            throw new \InvalidArgumentException("Session status '{$session->status}' does not allow execution");
        }

        $resolutionQueue = $session->resolution_queue ?? [];
        $priceListVersion = $session->priceListVersion;
        $supplierId = $session->supplier_id;

        // Merge decisions into resolution queue
        $mergedQueue = $this->mergeDecisions($resolutionQueue, $decisions);

        // Validate all decisions before execution
        $this->validateDecisions($mergedQueue, $session);

        $session->status = PriceImportSession::STATUS_EXECUTION_RUNNING;
        $session->save();

        $result = [
            'created_supplier_operations' => 0,
            'created_prices' => 0,
            'updated_prices' => 0,
            'created_aliases' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        try {
            DB::beginTransaction();

            foreach ($mergedQueue as $item) {
                $action = $item['decision']['action'] ?? $item['status'] ?? null;

                if ($action === 'ignore' || $item['status'] === 'ignored') {
                    $result['skipped']++;
                    continue;
                }

                try {
                    $itemResult = $this->processItem($item, $session, $priceListVersion, $supplierId);
                    
                    // operation_matched используется вместо created_supplier_operation
                    $result['created_supplier_operations'] += $itemResult['operation_matched'] ? 1 : 0;
                    $result['created_prices'] += $itemResult['created_price'] ? 1 : 0;
                    $result['updated_prices'] += $itemResult['updated_price'] ? 1 : 0;
                    $result['created_aliases'] += $itemResult['alias_created'] ? 1 : 0;
                } catch (\Exception $e) {
                    $result['errors'][] = [
                        'row_index' => $item['row_index'],
                        'error' => $e->getMessage(),
                    ];
                    Log::error('Price import item error', [
                        'session_id' => $session->id,
                        'row_index' => $item['row_index'],
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    
                    // Прерываем импорт при первой ошибке
                    throw $e;
                }
            }

            // Проверяем наличие ошибок перед коммитом
            if (!empty($result['errors'])) {
                throw new \Exception('Импорт прерван из-за ошибок при обработке позиций');
            }

            // Update session
            $session->status = PriceImportSession::STATUS_COMPLETED;
            $session->result = $result;
            $session->save();

            // Activate version if draft
            if ($priceListVersion->status === PriceListVersion::STATUS_INACTIVE) {
                $priceListVersion->activate();
            }

            DB::commit();

            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            
            $session->markExecutionFailed($e->getMessage(), [
                'partial_result' => $result,
            ]);
            
            throw $e;
        }
    }

    /**
     * Validate that supplier and price list version are set.
     * 
     * @throws \InvalidArgumentException
     */
    private function validateRequirements(PriceImportSession $session): void
    {
        if (!$session->supplier_id) {
            throw new \InvalidArgumentException(
                'Поставщик обязателен для импорта цен. Выберите поставщика перед импортом.'
            );
        }

        if (!$session->price_list_version_id) {
            throw new \InvalidArgumentException(
                'Версия прайс-листа обязательна для импорта цен. Выберите или создайте прайс-лист.'
            );
        }
    }

    /**
     * Process single item from resolution queue.
     */
    private function processItem(
        array $item,
        PriceImportSession $session,
        PriceListVersion $version,
        int $supplierId
    ): array {
        $result = [
            'operation_matched' => false,
            'created_price' => false,
            'updated_price' => false,
            'alias_created' => false,
        ];

        $rawData = $item['raw_data'];
        $decision = $item['decision'] ?? null;
        $status = $item['status'];
        $targetType = $session->target_type;

        // Calculate price
        $sourcePrice = $rawData['price'] ?? $rawData['cost_per_unit'] ?? 0;
        $conversionFactor = $decision['conversion_factor'] ?? $item['alias']['conversion_factor'] ?? 1.0;
        
        if ($conversionFactor <= 0) {
            throw new \InvalidArgumentException('Conversion factor must be positive');
        }

        $priceValue = $sourcePrice / $conversionFactor;
        $unit = $this->normalizeUnit($rawData['unit'] ?? null);

        if ($targetType === 'operations') {
            // Для операций: сопоставляем с базовой операцией и пишем в operation_prices
            $operationId = $this->resolveOperationId($item, $decision, $status, $rawData, $session->user_id);
            
            if ($operationId) {
                $result['operation_matched'] = true;
            }
            
            // Сохраняем цену в operation_prices (даже без привязки к базовой операции)
            $this->saveOperationPrice(
                $operationId,
                $supplierId,
                $version->id,
                $priceValue,
                $unit,
                $version->currency,
                $rawData,
                $item,
                $result
            );
            
            // Сохраняем alias для будущего авто-сопоставления (только если привязана)
            if ($operationId) {
                $matchConfidence = $item['match_confidence'] ?? 'manual';
                $this->saveOperationAlias(
                    $supplierId,
                    $operationId,
                    $rawData,
                    $conversionFactor,
                    $unit,
                    $matchConfidence
                );
                $result['alias_created'] = true;
            }

        } else {
            // Для материалов: создаем material_price с supplier_id
            $materialId = $this->resolveMaterialId($item, $decision, $status, $session->user_id, $rawData);
            
            if ($materialId) {
                $this->saveMaterialPrice(
                    $materialId,
                    $supplierId,
                    $version->id,
                    $priceValue,
                    $unit,
                    $version->currency,
                    $rawData,
                    $item['row_index'],
                    $result
                );
                
                // Create/update material alias
                $this->saveMaterialAlias(
                    $supplierId,
                    $materialId,
                    $rawData,
                    $conversionFactor,
                    $decision['supplier_unit'] ?? $unit,
                    $decision['internal_unit'] ?? null,
                    $status === 'auto_matched' ? 'auto_exact' : 'manual'
                );
                $result['alias_created'] = true;
            }
        }

        return $result;
    }

    /**
     * Resolve operation ID from base operations table.
     */
    private function resolveOperationId(
        array $item,
        ?array $decision,
        string $status,
        array $rawData,
        int $userId
    ): ?int {
        // Explicit user decision "create" for operations means:
        // create (or reuse by exact name) a base operation and bind to it.
        if ($decision && ($decision['action'] ?? null) === 'create') {
            return $this->resolveOrCreateOperationFromRow($rawData, $userId);
        }

        if ($decision && ($decision['action'] ?? null) === 'ignore') {
            return null;
        }

        // Если уже есть matched_item_id (авто-сопоставление)
        if ($status === 'auto_matched' && !empty($item['matched_item_id'])) {
            $autoId = (int) $item['matched_item_id'];
            try {
                $this->assertManualLinkConsistency($rawData['name'] ?? '', $autoId, $item['row_index'] ?? null);
                return $autoId;
            } catch (\InvalidArgumentException $e) {
                // Do not silently bind wrong operation on poisoned auto-matches.
                \Log::warning('Auto-match rejected as inconsistent', [
                    'row_index' => $item['row_index'] ?? null,
                    'source_name' => $rawData['name'] ?? null,
                    'matched_item_id' => $autoId,
                    'reason' => $e->getMessage(),
                ]);
                return null;
            }
        }

        // Если пользователь выбрал операцию вручную
        if ($decision && $decision['action'] === 'link' && !empty($decision['internal_item_id'])) {
            $operationId = (int) $decision['internal_item_id'];
            $this->assertManualLinkConsistency($rawData['name'] ?? '', $operationId, $item['row_index'] ?? null);
            return $operationId;
        }

        // Для не auto_matched строк без явного "link" больше не делаем скрытый rematch:
        // такие строки должны остаться непривязанными (operation_id = null),
        // чтобы пользователь контролировал сопоставление явно.
        return null;
    }

    /**
     * Resolve existing base operation by exact normalized name or create a new user operation.
     */
    private function resolveOrCreateOperationFromRow(array $rawData, int $userId): int
    {
        $name = trim((string)($rawData['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Cannot create operation without name');
        }

        $normalizedName = Operation::normalizeSearchName($name);
        $existing = Operation::where('search_name', $normalizedName)->first();
        if ($existing) {
            return (int) $existing->id;
        }

        $unit = $this->normalizeUnit($rawData['unit'] ?? null) ?? 'шт.';
        $category = trim((string)($rawData['category'] ?? 'Импорт')) ?: 'Импорт';

        $operation = Operation::create([
            'name' => $name,
            'category' => $category,
            'unit' => $unit,
            'description' => $rawData['description'] ?? null,
            'user_id' => $userId,
            'origin' => 'user',
        ]);

        return (int) $operation->id;
    }

    /**
     * Save operation price to operation_prices table.
     * operation_id can be null for unlinked price rows.
     */
    private function saveOperationPrice(
        ?int $operationId,
        int $supplierId,
        int $versionId,
        float $priceValue,
        ?string $unit,
        string $currency,
        array $rawData,
        array $item,
        array &$result
    ): void {
        $externalKey = $rawData['sku'] ?? $rawData['article'] ?? md5($rawData['name']);
        $matchConfidence = $item['match_confidence'] ?? ($operationId ? OperationPrice::MATCH_MANUAL : null);

        // Build lookup query - for linked operations use operation_id, for unlinked use source_name
        $query = OperationPrice::where('supplier_id', $supplierId)
            ->where('price_list_version_id', $versionId)
            ->where('price_type', OperationPrice::PRICE_TYPE_RETAIL);
        
        if ($operationId) {
            $query->where('operation_id', $operationId);
        } else {
            $query->whereNull('operation_id')
                  ->where('source_name', $rawData['name']);
        }

        $existing = $query->first();

        $priceData = [
            'price_per_internal_unit' => $priceValue,
            'source_price' => $rawData['price'] ?? $rawData['cost_per_unit'] ?? 0,
            'source_unit' => $unit,
            'currency' => $currency,
            'source_name' => $rawData['name'],
            'external_key' => $externalKey,
            'match_confidence' => $matchConfidence,
            'meta' => [
                'source_row_index' => $item['row_index'] ?? null,
                'raw_price' => $rawData['price'] ?? null,
                'raw_unit' => $rawData['unit'] ?? null,
                'category' => $rawData['category'] ?? null,
            ],
        ];

        if ($existing) {
            $existing->update($priceData);
            $result['updated_price'] = true;
        } else {
            OperationPrice::create(array_merge($priceData, [
                'operation_id' => $operationId,
                'supplier_id' => $supplierId,
                'price_list_version_id' => $versionId,
                'price_type' => OperationPrice::PRICE_TYPE_RETAIL,
            ]));
            $result['created_price'] = true;
        }
    }

    /**
     * Resolve material ID based on decision.
     */
    private function resolveMaterialId(
        array $item,
        ?array $decision,
        string $status,
        int $userId,
        array $rawData
    ): ?int {
        if ($status === 'auto_matched') {
            return $item['matched_item_id'];
        }

        if ($decision && $decision['action'] === 'link') {
            return $decision['internal_item_id'];
        }

        if ($decision && $decision['action'] === 'create') {
            // Create new material
            $article = $rawData['sku'] ?? $rawData['article'] ?? 'IMP-' . substr(md5($rawData['name']), 0, 8);
            
            $material = Material::create([
                'name' => $rawData['name'],
                'article' => $article,
                'type' => $rawData['type'] ?? 'other',
                'category' => $rawData['category'] ?? 'Импорт',
                'price_per_unit' => 0, // НЕ устанавливаем базовую цену!
                'unit' => $rawData['unit'] ?? 'шт',
                'description' => $rawData['description'] ?? null,
                'thickness' => $rawData['thickness'] ?? null,
                'user_id' => $userId,
                'origin' => 'import',
                'search_name' => TextNormalizer::normalize($rawData['name']),
            ]);

            return $material->id;
        }

        return null;
    }

    /**
     * Save material price with supplier.
     */
    private function saveMaterialPrice(
        int $materialId,
        int $supplierId,
        int $versionId,
        float $priceValue,
        ?string $unit,
        string $currency,
        array $rawData,
        int $rowIndex,
        array &$result
    ): void {
        $existing = MaterialPrice::where('material_id', $materialId)
            ->where('supplier_id', $supplierId)
            ->where('price_list_version_id', $versionId)
            ->first();

        $priceData = [
            'price_per_internal_unit' => $priceValue,
            'source_unit' => $unit,
            'currency' => $currency,
            'source_row_index' => $rowIndex,
            'article' => $rawData['sku'] ?? $rawData['article'] ?? null,
            'category' => $rawData['category'] ?? null,
            'description' => $rawData['description'] ?? null,
            'thickness' => $rawData['thickness'] ?? null,
        ];

        if ($existing) {
            $existing->update($priceData);
            $result['updated_price'] = true;
        } else {
            MaterialPrice::create(array_merge($priceData, [
                'material_id' => $materialId,
                'supplier_id' => $supplierId,
                'price_list_version_id' => $versionId,
                'source_price' => $priceValue, // Same as internal for now
                'conversion_factor' => 1.0,
            ]));
            $result['created_price'] = true;
        }
    }

    /**
     * Save operation alias (for base operations).
     */
    private function saveOperationAlias(
        int $supplierId,
        int $operationId,
        array $rawData,
        float $conversionFactor,
        ?string $supplierUnit,
        string $confidence
    ): void {
        $externalKey = $rawData['sku'] ?? $rawData['article'] ?? SupplierProductAlias::generateExternalKey($rawData['name']);
        $supplierUnit = $this->normalizeUnit($supplierUnit);

        SupplierProductAlias::updateOrCreate(
            [
                'supplier_id' => $supplierId,
                'external_key' => $externalKey,
                'internal_item_type' => SupplierProductAlias::TYPE_OPERATION,
            ],
            [
                'internal_item_id' => $operationId,
                'external_name' => $rawData['name'],
                'supplier_unit' => $supplierUnit,
                'conversion_factor' => $conversionFactor,
                'confidence' => $confidence,
            ]
        );
    }

    private function normalizeUnit(?string $unit): ?string
    {
        return OperationPrice::normalizeUnit($unit);
    }

    /**
     * Protect manual mapping from obvious semantic mismatches.
     */
    private function assertManualLinkConsistency(string $sourceName, int $operationId, ?int $rowIndex = null): void
    {
        $operation = Operation::find($operationId);
        if (!$operation) {
            throw new \InvalidArgumentException("Operation {$operationId} not found for manual link");
        }

        $source = Operation::normalizeSearchName($sourceName);
        $target = Operation::normalizeSearchName($operation->name);

        if ($source === '' || $target === '') {
            return;
        }

        $markers = [
            'распил',
            'кромкооблицов',
            'криволин',
            'прямолин',
            'покрыт',
            'глян',
        ];

        foreach ($markers as $marker) {
            $sourceHas = mb_strpos($source, $marker) !== false;
            $targetHas = mb_strpos($target, $marker) !== false;
            if ($sourceHas !== $targetHas) {
                $prefix = $rowIndex !== null ? "row {$rowIndex}: " : '';
                throw new \InvalidArgumentException(
                    $prefix . "manual link mismatch '{$sourceName}' -> '{$operation->name}' (marker '{$marker}')"
                );
            }
        }

        // If both names have dimensional marker like 1,0x19, require equality.
        $sourceDim = $this->extractDimOrDiameterToken($sourceName);
        $targetDim = $this->extractDimOrDiameterToken($operation->name);
        if ($sourceDim !== null && $targetDim !== null && $sourceDim !== $targetDim) {
            $prefix = $rowIndex !== null ? "row {$rowIndex}: " : '';
            throw new \InvalidArgumentException(
                $prefix . "manual link mismatch '{$sourceName}' -> '{$operation->name}' (dim '{$sourceDim}' vs '{$targetDim}')"
            );
        }
    }

    private function extractDimOrDiameterToken(string $value): ?string
    {
        if (preg_match('/\b(\d+(?:[.,]\d+)?)\s*[xх]\s*(\d+(?:[.,]\d+)?)\b/u', $value, $m)) {
            $a = str_replace(',', '.', $m[1]);
            $b = str_replace(',', '.', $m[2]);
            return "dim:{$a}x{$b}";
        }

        if (preg_match('/(?:диаметром|d)\s*(\d+(?:[.,]\d+)?)\b/ui', $value, $m)) {
            $d = str_replace(',', '.', $m[1]);
            return "dia:{$d}";
        }

        return null;
    }

    /**
     * Save material alias.
     */
    private function saveMaterialAlias(
        int $supplierId,
        int $materialId,
        array $rawData,
        float $conversionFactor,
        ?string $supplierUnit,
        ?string $internalUnit,
        string $confidence
    ): void {
        $externalKey = $rawData['sku'] ?? $rawData['article'] ?? SupplierProductAlias::generateExternalKey($rawData['name']);

        SupplierProductAlias::updateOrCreate(
            [
                'supplier_id' => $supplierId,
                'external_key' => $externalKey,
                'internal_item_type' => SupplierProductAlias::TYPE_MATERIAL,
            ],
            [
                'internal_item_id' => $materialId,
                'external_name' => $rawData['name'],
                'supplier_unit' => $supplierUnit,
                'internal_unit' => $internalUnit,
                'conversion_factor' => $conversionFactor,
                'confidence' => $confidence,
            ]
        );
    }

    /**
     * Merge user decisions into resolution queue.
     */
    private function mergeDecisions(array $queue, array $decisions): array
    {
        $decisionsMap = [];
        foreach ($decisions as $decision) {
            $decisionsMap[$decision['row_index']] = $decision;
        }

        foreach ($queue as &$item) {
            if (isset($decisionsMap[$item['row_index']])) {
                $item['decision'] = $decisionsMap[$item['row_index']];
            }
        }

        return $queue;
    }

    /**
     * Validate all decisions before execution.
     */
    private function validateDecisions(array $queue, PriceImportSession $session): void
    {
        foreach ($queue as $item) {
            $status = $item['status'];
            $decision = $item['decision'] ?? null;

            // Auto-matched items don't need decisions for operations (we create supplier_operation)
            if ($status === 'auto_matched') {
                // For materials, we still need matched_item_id
                if ($session->target_type === 'materials' && !$item['matched_item_id']) {
                    throw new \InvalidArgumentException("Auto-matched material at row {$item['row_index']} has no item ID");
                }
                continue;
            }

            // New or ambiguous items need decisions for materials
            if ($session->target_type === 'materials' && in_array($status, ['new', 'ambiguous'])) {
                if (!$decision) {
                    throw new \InvalidArgumentException("Missing decision for row {$item['row_index']} (status: {$status})");
                }

                $action = $decision['action'] ?? null;
                if (!in_array($action, ['link', 'create', 'ignore'])) {
                    throw new \InvalidArgumentException("Invalid action '{$action}' for row {$item['row_index']}");
                }

                if ($action === 'link' && empty($decision['internal_item_id'])) {
                    throw new \InvalidArgumentException("Link action requires internal_item_id for row {$item['row_index']}");
                }
            }

            // Validate conversion factor if provided
            if ($decision) {
                $conversionFactor = $decision['conversion_factor'] ?? 1.0;
                if ($conversionFactor <= 0) {
                    throw new \InvalidArgumentException("Invalid conversion factor for row {$item['row_index']}");
                }
            }
        }
    }
}
