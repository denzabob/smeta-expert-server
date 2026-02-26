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
 * Сервис выполнения импорта (запись цен и алиасов)
 */
class PriceImportExecutor
{
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
        if (!$session->canExecute()) {
            throw new \InvalidArgumentException("Session status '{$session->status}' does not allow execution");
        }

        $resolutionQueue = $session->resolution_queue ?? [];
        $priceListVersion = $session->priceListVersion; // Can be null

        // Merge decisions into resolution queue
        $mergedQueue = $this->mergeDecisions($resolutionQueue, $decisions);

        // Validate all decisions before execution
        $this->validateDecisions($mergedQueue, $session);

        $session->status = PriceImportSession::STATUS_EXECUTION_RUNNING;
        $session->save();

        $result = [
            'created_items' => 0,
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
                    $itemResult = $this->processItem($item, $session, $priceListVersion);
                    
                    $result['created_items'] += $itemResult['created_item'] ? 1 : 0;
                    $result['updated_prices'] += $itemResult['price_saved'] ? 1 : 0;
                    $result['created_aliases'] += $itemResult['alias_created'] ? 1 : 0;
                } catch (\Exception $e) {
                    $result['errors'][] = [
                        'row_index' => $item['row_index'],
                        'error' => $e->getMessage(),
                    ];
                    Log::warning('Price import item error', [
                        'session_id' => $session->id,
                        'row_index' => $item['row_index'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Update session
            $session->status = PriceImportSession::STATUS_COMPLETED;
            $session->result = $result;
            $session->save();

            // Activate version if draft (only if version exists)
            if ($priceListVersion && $priceListVersion->status === PriceListVersion::STATUS_INACTIVE) {
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
     * Process single item from resolution queue.
     */
    private function processItem(array $item, PriceImportSession $session, ?PriceListVersion $version): array
    {
        $result = [
            'created_item' => false,
            'price_saved' => false,
            'alias_created' => false,
        ];

        $rawData = $item['raw_data'];
        $decision = $item['decision'] ?? null;
        $status = $item['status'];
        $targetType = $session->target_type;

        // Get internal item ID
        $internalItemId = null;

        if ($status === 'auto_matched') {
            $internalItemId = $item['matched_item_id'];
        } elseif ($decision && $decision['action'] === 'link') {
            $internalItemId = $decision['internal_item_id'];
        } elseif ($decision && $decision['action'] === 'create') {
            // Create new item
            $internalItemId = $this->createNewItem($rawData, $targetType, $session->user_id);
            $result['created_item'] = true;
        }

        if (!$internalItemId) {
            return $result;
        }

        // Determine conversion factor
        $conversionFactor = $decision['conversion_factor'] ?? $item['alias']['conversion_factor'] ?? 1.0;
        
        if ($conversionFactor <= 0) {
            throw new \InvalidArgumentException('Conversion factor must be positive');
        }

        // Calculate prices
        $sourcePrice = $rawData['price'] ?? $rawData['cost_per_unit'] ?? 0;
        $pricePerInternalUnit = $sourcePrice / $conversionFactor;

        // Save price (only if version exists)
        if ($version) {
            $this->savePrice(
                $version,
                $targetType,
                $internalItemId,
                $sourcePrice,
                $pricePerInternalUnit,
                $conversionFactor,
                $rawData,
                $item['row_index']
            );
        } else {
            // Update base price directly in item
            $this->updateBasePrice($targetType, $internalItemId, $pricePerInternalUnit);
        }
        $result['price_saved'] = true;

        // Create/update alias if supplier is set
        if ($session->supplier_id) {
            $this->saveAlias(
                $session->supplier_id,
                $targetType,
                $internalItemId,
                $rawData,
                $conversionFactor,
                $decision['supplier_unit'] ?? $rawData['unit'] ?? null,
                $decision['internal_unit'] ?? null,
                $status === 'auto_matched' ? 'auto_exact' : 'manual'
            );
            $result['alias_created'] = true;
        }

        return $result;
    }

    /**
     * Create new item in catalog.
     */
    private function createNewItem(array $rawData, string $type, int $userId): int
    {
        if ($type === 'operations') {
            $operation = Operation::create([
                'name' => $rawData['name'],
                'category' => $rawData['category'] ?? 'Импорт',
                'cost_per_unit' => $rawData['cost_per_unit'] ?? $rawData['price'] ?? 0,
                'unit' => $rawData['unit'] ?? 'шт',
                'description' => $rawData['description'] ?? null,
                'min_thickness' => $rawData['min_thickness'] ?? null,
                'max_thickness' => $rawData['max_thickness'] ?? null,
                'exclusion_group' => $rawData['exclusion_group'] ?? null,
                'user_id' => $userId,
                'origin' => 'import',
                'search_name' => TextNormalizer::normalize($rawData['name']),
            ]);
            return $operation->id;
        } else {
            // Generate article if not provided (required field)
            $article = $rawData['sku'] ?? $rawData['article'] ?? 'IMP-' . substr(md5($rawData['name']), 0, 8);
            
            $material = Material::create([
                'name' => $rawData['name'],
                'article' => $article,
                'type' => $rawData['type'] ?? 'other',
                'category' => $rawData['category'] ?? 'Импорт',
                'price_per_unit' => $rawData['price'] ?? 0,
                'unit' => $rawData['unit'] ?? 'шт',
                'description' => $rawData['description'] ?? null,
                'thickness' => $rawData['thickness'] ?? null,
                'user_id' => $userId,
                'origin' => 'import',
                'search_name' => TextNormalizer::normalize($rawData['name']),
            ]);
            return $material->id;
        }
    }

    /**
     * Save price record.
     */
    private function savePrice(
        PriceListVersion $version,
        string $type,
        int $itemId,
        float $sourcePrice,
        float $pricePerInternalUnit,
        float $conversionFactor,
        array $rawData,
        int $rowIndex
    ): void {
        $priceData = [
            'price_list_version_id' => $version->id,
            'source_price' => $sourcePrice,
            'source_unit' => $rawData['unit'] ?? null,
            'conversion_factor' => $conversionFactor,
            'price_per_internal_unit' => $pricePerInternalUnit,
            'currency' => $version->currency,
            'source_row_index' => $rowIndex,
        ];

        if ($type === 'operations') {
            $priceData['operation_id'] = $itemId;
            $priceData['category'] = $rawData['category'] ?? null;
            $priceData['description'] = $rawData['description'] ?? null;
            $priceData['min_thickness'] = $rawData['min_thickness'] ?? null;
            $priceData['max_thickness'] = $rawData['max_thickness'] ?? null;
            $priceData['exclusion_group'] = $rawData['exclusion_group'] ?? null;

            OperationPrice::updateOrCreate(
                [
                    'price_list_version_id' => $version->id,
                    'operation_id' => $itemId,
                ],
                $priceData
            );
        } else {
            $priceData['material_id'] = $itemId;
            $priceData['article'] = $rawData['sku'] ?? $rawData['article'] ?? null;
            $priceData['category'] = $rawData['category'] ?? null;
            $priceData['description'] = $rawData['description'] ?? null;
            $priceData['thickness'] = $rawData['thickness'] ?? null;

            MaterialPrice::updateOrCreate(
                [
                    'price_list_version_id' => $version->id,
                    'material_id' => $itemId,
                ],
                $priceData
            );
        }
    }

    /**
     * Save or update alias.
     */
    private function saveAlias(
        int $supplierId,
        string $type,
        int $itemId,
        array $rawData,
        float $conversionFactor,
        ?string $supplierUnit,
        ?string $internalUnit,
        string $confidence
    ): void {
        $externalKey = $rawData['sku'] ?? $rawData['article'] ?? SupplierProductAlias::generateExternalKey($rawData['name']);
        $internalType = $type === 'operations'
            ? SupplierProductAlias::TYPE_OPERATION
            : SupplierProductAlias::TYPE_MATERIAL;

        $alias = SupplierProductAlias::updateOrCreate(
            [
                'supplier_id' => $supplierId,
                'external_key' => $externalKey,
                'internal_item_type' => $internalType,
            ],
            [
                'internal_item_id' => $itemId,
                'external_name' => $rawData['name'],
                'supplier_unit' => $supplierUnit,
                'internal_unit' => $internalUnit,
                'conversion_factor' => $conversionFactor,
                'confidence' => $confidence,
            ]
        );

        $alias->recordUsage();
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
     * Update base price directly in item (when no price list version).
     */
    private function updateBasePrice(string $type, int $itemId, float $price): void
    {
        if ($type === 'operations') {
            Operation::where('id', $itemId)->update([
                'cost_per_unit' => $price,
            ]);
        } else {
            Material::where('id', $itemId)->update([
                'price' => $price,
            ]);
        }
    }

    /**
     * Validate all decisions before execution.
     */
    private function validateDecisions(array $queue, PriceImportSession $session): void
    {
        foreach ($queue as $item) {
            $status = $item['status'];
            $decision = $item['decision'] ?? null;

            // Auto-matched items don't need decisions
            if ($status === 'auto_matched') {
                if (!$item['matched_item_id']) {
                    throw new \InvalidArgumentException("Auto-matched item at row {$item['row_index']} has no item ID");
                }
                continue;
            }

            // New or ambiguous items need decisions
            if (in_array($status, ['new', 'ambiguous'])) {
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

                // Validate conversion factor
                $conversionFactor = $decision['conversion_factor'] ?? 1.0;
                if ($conversionFactor <= 0) {
                    throw new \InvalidArgumentException("Invalid conversion factor for row {$item['row_index']}");
                }
            }
        }
    }
}
