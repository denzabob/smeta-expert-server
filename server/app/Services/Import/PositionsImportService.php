<?php

namespace App\Services\Import;

use App\Models\ImportColumnMapping;
use App\Models\ImportSession;
use App\Models\Material;
use App\Models\MaterialPrice;
use App\Models\PriceListVersion;
use App\Models\Project;
use App\Models\ProjectPosition;
use App\Models\SupplierProductAlias;
use App\Utilities\NumericNormalizer;
use App\Utilities\SpreadsheetReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Service for importing positions from spreadsheet files.
 */
class PositionsImportService
{
    private const BATCH_SIZE = 100;

    public function __construct(
        private ImportSessionService $sessionService
    ) {}

    /**
     * Run the import for a session.
     *
     * @param ImportSession $session The import session (must be in 'mapped' status)
     * @param Project $project The target project
     * @param string $mode Import mode: 'append' (default)
     * @return array Import result with counts and errors
     */
    public function run(ImportSession $session, Project $project, string $mode = 'append'): array
    {
        if ($session->status !== ImportSession::STATUS_MAPPED) {
            throw new RuntimeException('Import session must be in "mapped" status to run import.');
        }

        // Load column mappings
        $session->load('columnMappings');
        
        // Get column indices for each field
        $widthColumn = $session->getColumnIndexForField(ImportColumnMapping::FIELD_WIDTH);
        $lengthColumn = $session->getColumnIndexForField(ImportColumnMapping::FIELD_LENGTH);
        $qtyColumn = $session->getColumnIndexForField(ImportColumnMapping::FIELD_QTY);
        $nameColumn = $session->getColumnIndexForField(ImportColumnMapping::FIELD_NAME);
        $kindColumn = $session->getColumnIndexForField(ImportColumnMapping::FIELD_KIND);
        $priceItemCodeColumn = $session->getColumnIndexForField(ImportColumnMapping::FIELD_PRICE_ITEM_CODE);
        $heightColumn = $session->getColumnIndexForField(ImportColumnMapping::FIELD_HEIGHT);

        if ($widthColumn === null || $lengthColumn === null) {
            throw new RuntimeException('Width and length columns must be mapped before import.');
        }

        // Get options
        $unitsLength = $session->getOption('units_length', 'mm');
        $defaultQty = $session->getOption('default_qty_if_empty', 1);
        $skipEmptyRows = $session->getOption('skip_empty_rows', true);
        $defaultKind = $session->getOption('default_kind', 'panel');
        $defaultFacadeMaterialId = $session->getOption('default_facade_material_id', null);

        // Initialize counters and error collection
        $createdCount = 0;
        $createdFacadesCount = 0;
        $skippedCount = 0;
        $errors = [];
        $createdIds = [];

        // Get reader and start from after header row
        $reader = $this->sessionService->getReader($session);
        $startRow = $session->header_row_index + 1; // Start after header

        // Batch insert buffer
        $batch = [];

        try {
            DB::beginTransaction();

            foreach ($reader->iterateRows($startRow, $session->sheet_index) as $rowIndex => $rowData) {
                $rowNumber = $rowIndex + 1; // 1-based for user display

                // Skip empty rows if enabled
                if ($skipEmptyRows && $this->isRowEmpty($rowData)) {
                    $skippedCount++;
                    continue;
                }

                // Parse width
                $widthRaw = $rowData[$widthColumn] ?? null;
                $width = NumericNormalizer::toPositiveFloat($widthRaw);
                
                if ($width === null) {
                    $skippedCount++;
                    $errors[] = [
                        'row' => $rowNumber,
                        'reason' => "Invalid or missing width value: " . json_encode($widthRaw),
                    ];
                    continue;
                }

                // Parse length
                $lengthRaw = $rowData[$lengthColumn] ?? null;
                $length = NumericNormalizer::toPositiveFloat($lengthRaw);
                
                if ($length === null) {
                    $skippedCount++;
                    $errors[] = [
                        'row' => $rowNumber,
                        'reason' => "Invalid or missing length value: " . json_encode($lengthRaw),
                    ];
                    continue;
                }

                // Parse quantity
                $qty = $defaultQty;
                if ($qtyColumn !== null) {
                    $qtyRaw = $rowData[$qtyColumn] ?? null;
                    if ($qtyRaw !== null && $qtyRaw !== '') {
                        $parsedQty = NumericNormalizer::toPositiveInt($qtyRaw, true);
                        if ($parsedQty === null) {
                            $skippedCount++;
                            $errors[] = [
                                'row' => $rowNumber,
                                'reason' => "Invalid quantity value (must be positive integer): " . json_encode($qtyRaw),
                            ];
                            continue;
                        }
                        $qty = $parsedQty;
                    }
                    // If qtyRaw is empty, use defaultQty
                }

                // Parse custom name
                $customName = null;
                if ($nameColumn !== null) {
                    $nameRaw = $rowData[$nameColumn] ?? null;
                    if ($nameRaw !== null && trim((string)$nameRaw) !== '') {
                        $customName = trim((string)$nameRaw);
                    }
                }

                // Convert units to mm
                $widthMm = NumericNormalizer::convertToMm($width, $unitsLength);
                $lengthMm = NumericNormalizer::convertToMm($length, $unitsLength);

                // Determine kind (panel or facade)
                $kind = $defaultKind; // Use default_kind from options
                if ($kindColumn !== null) {
                    $kindRaw = trim(mb_strtolower((string)($rowData[$kindColumn] ?? '')));
                    if (in_array($kindRaw, ['facade', 'фасад', 'f'])) {
                        $kind = 'facade';
                    } elseif (in_array($kindRaw, ['panel', 'панель', 'p'])) {
                        $kind = 'panel';
                    }
                }

                // If height column is mapped, use that as length (height→length for facades)
                if ($heightColumn !== null && $kind === 'facade') {
                    $heightRaw = $rowData[$heightColumn] ?? null;
                    $heightVal = NumericNormalizer::toPositiveFloat($heightRaw);
                    if ($heightVal !== null) {
                        $lengthMm = NumericNormalizer::convertToMm($heightVal, $unitsLength);
                    }
                }

                // Build base record
                $record = [
                    'project_id' => $project->id,
                    'kind' => $kind,
                    'width' => $widthMm,
                    'length' => $lengthMm,
                    'quantity' => $qty,
                    'custom_name' => $customName,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                // Handle facade: resolve price_item_code → material + price
                if ($kind === 'facade') {
                    if ($priceItemCodeColumn !== null) {
                        $priceItemCode = trim((string)($rowData[$priceItemCodeColumn] ?? ''));
                        if ($priceItemCode !== '') {
                            $facadeData = $this->resolveFacadeFromPriceItemCode($priceItemCode);
                            if ($facadeData === null) {
                                $skippedCount++;
                                $errors[] = [
                                    'row' => $rowNumber,
                                    'reason' => "Facade price item code not found: " . json_encode($priceItemCode),
                                ];
                                continue;
                            }
                            $record = array_merge($record, $facadeData);
                            $createdFacadesCount++;
                        } elseif ($defaultFacadeMaterialId) {
                            // No code in this row but have a default facade material
                            $facadeData = $this->resolveFacadeFromMaterialId($defaultFacadeMaterialId);
                            if ($facadeData) {
                                $record = array_merge($record, $facadeData);
                                $createdFacadesCount++;
                            }
                        }
                        // else: facade without material is allowed (dimensions only)
                    } elseif ($defaultFacadeMaterialId) {
                        // No price_item_code column but have a default facade material
                        $facadeData = $this->resolveFacadeFromMaterialId($defaultFacadeMaterialId);
                        if ($facadeData) {
                            $record = array_merge($record, $facadeData);
                            $createdFacadesCount++;
                        }
                    }
                    // else: facade with dimensions only (no material) — allowed
                    // Clear panel fields for facade
                    $record['edge_material_id'] = null;
                    $record['edge_scheme'] = 'none';
                }

                // Add to batch
                $batch[] = $record;

                // Insert batch if full
                if (count($batch) >= self::BATCH_SIZE) {
                    $ids = $this->insertBatch($batch);
                    $createdCount += count($ids);
                    $createdIds = array_merge($createdIds, $ids);
                    $batch = [];
                }
            }

            // Insert remaining batch
            if (!empty($batch)) {
                $ids = $this->insertBatch($batch);
                $createdCount += count($ids);
                $createdIds = array_merge($createdIds, $ids);
            }

            DB::commit();

            // Update session status
            $result = [
                'created_count' => $createdCount,
                'created_facades_count' => $createdFacadesCount,
                'skipped_count' => $skippedCount,
                'errors_count' => count($errors),
                'errors' => array_slice($errors, 0, 100), // Limit stored errors
            ];

            $session->status = ImportSession::STATUS_IMPORTED;
            $session->result = $result;
            $session->save();

            Log::info('Position import completed', [
                'session_id' => $session->id,
                'project_id' => $project->id,
                'created' => $createdCount,
                'skipped' => $skippedCount,
                'errors' => count($errors),
            ]);

            return array_merge($result, [
                'sample_created_ids' => array_slice($createdIds, 0, 10),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Position import failed', [
                'session_id' => $session->id,
                'project_id' => $project->id,
                'error' => $e->getMessage(),
            ]);

            // Update session status
            $session->status = ImportSession::STATUS_FAILED;
            $session->result = [
                'error' => $e->getMessage(),
                'created_count' => $createdCount,
                'skipped_count' => $skippedCount,
                'errors' => $errors,
            ];
            $session->save();

            throw $e;
        }
    }

    /**
     * Check if a row is empty.
     *
     * @param array $row Row data
     * @return bool
     */
    private function isRowEmpty(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== null && $cell !== '') {
                return false;
            }
        }
        return true;
    }

    /**
     * Insert a batch of positions.
     *
     * @param array $batch Batch of position data
     * @return array Array of created IDs
     */
    private function insertBatch(array $batch): array
    {
        $ids = [];
        
        foreach ($batch as $data) {
            $position = ProjectPosition::create($data);
            $ids[] = $position->id;
        }

        return $ids;
    }

    /**
     * Get a preview of what will be imported (dry run).
     *
     * @param ImportSession $session The import session
     * @param int $maxRows Maximum rows to preview
     * @return array Preview data
     */
    public function preview(ImportSession $session, int $maxRows = 10): array
    {
        if ($session->status !== ImportSession::STATUS_MAPPED) {
            throw new RuntimeException('Import session must be in "mapped" status for preview.');
        }

        $session->load('columnMappings');
        
        $widthColumn = $session->getColumnIndexForField(ImportColumnMapping::FIELD_WIDTH);
        $lengthColumn = $session->getColumnIndexForField(ImportColumnMapping::FIELD_LENGTH);
        $qtyColumn = $session->getColumnIndexForField(ImportColumnMapping::FIELD_QTY);
        $nameColumn = $session->getColumnIndexForField(ImportColumnMapping::FIELD_NAME);

        if ($widthColumn === null || $lengthColumn === null) {
            throw new RuntimeException('Width and length columns must be mapped.');
        }

        $unitsLength = $session->getOption('units_length', 'mm');
        $defaultQty = $session->getOption('default_qty_if_empty', 1);
        $defaultKind = $session->getOption('default_kind', 'panel');

        $reader = $this->sessionService->getReader($session);
        $startRow = $session->header_row_index + 1;

        $preview = [];
        $count = 0;

        foreach ($reader->iterateRows($startRow, $session->sheet_index) as $rowIndex => $rowData) {
            if ($count >= $maxRows) {
                break;
            }

            $rowNumber = $rowIndex + 1;
            
            $widthRaw = $rowData[$widthColumn] ?? null;
            $lengthRaw = $rowData[$lengthColumn] ?? null;
            $qtyRaw = $qtyColumn !== null ? ($rowData[$qtyColumn] ?? null) : null;
            $nameRaw = $nameColumn !== null ? ($rowData[$nameColumn] ?? null) : null;

            // Determine kind for preview
            $kindColumn = $session->getColumnIndexForField(ImportColumnMapping::FIELD_KIND);
            $kind = $defaultKind;
            if ($kindColumn !== null) {
                $kindRaw = trim(mb_strtolower((string)($rowData[$kindColumn] ?? '')));
                if (in_array($kindRaw, ['facade', 'фасад', 'f'])) {
                    $kind = 'facade';
                } elseif (in_array($kindRaw, ['panel', 'панель', 'p'])) {
                    $kind = 'panel';
                }
            }

            $width = NumericNormalizer::toPositiveFloat($widthRaw);
            $length = NumericNormalizer::toPositiveFloat($lengthRaw);
            $qty = $qtyRaw !== null && $qtyRaw !== '' 
                ? NumericNormalizer::toPositiveInt($qtyRaw, true) 
                : $defaultQty;

            $status = 'ok';
            $error = null;

            if ($width === null) {
                $status = 'error';
                $error = 'Invalid width';
            } elseif ($length === null) {
                $status = 'error';
                $error = 'Invalid length';
            } elseif ($qty === null) {
                $status = 'error';
                $error = 'Invalid quantity';
            }

            $preview[] = [
                'row' => $rowNumber,
                'raw' => [
                    'width' => $widthRaw,
                    'length' => $lengthRaw,
                    'qty' => $qtyRaw,
                    'name' => $nameRaw,
                ],
                'parsed' => [
                    'width_mm' => $width !== null ? NumericNormalizer::convertToMm($width, $unitsLength) : null,
                    'length_mm' => $length !== null ? NumericNormalizer::convertToMm($length, $unitsLength) : null,
                    'qty' => $qty,
                    'name' => $nameRaw !== null && trim((string)$nameRaw) !== '' ? trim((string)$nameRaw) : null,
                    'kind' => $kind,
                ],
                'status' => $status,
                'error' => $error,
            ];

            $count++;
        }

        return [
            'items' => $preview,
            'units_length' => $unitsLength,
            'default_qty' => $defaultQty,
        ];
    }

    /**
     * Resolve a facade from price_item_code (external_key in supplier_product_aliases).
     *
     * Looks up the alias → material(type=facade) → active price.
     * Returns array of fields to merge into the position record, or null if not found.
     */
    private function resolveFacadeFromPriceItemCode(string $code): ?array
    {
        // Find alias by external_key across all suppliers
        $alias = SupplierProductAlias::where('external_key', $code)
            ->where('internal_item_type', SupplierProductAlias::TYPE_MATERIAL)
            ->first();

        if (!$alias) {
            // Try by article in materials
            $material = Material::where('type', Material::TYPE_FACADE)
                ->where(function ($q) use ($code) {
                    $q->where('article', $code)
                      ->orWhere('article', 'LIKE', "%{$code}%");
                })
                ->first();

            if (!$material) {
                return null;
            }
        } else {
            $material = Material::where('type', Material::TYPE_FACADE)
                ->find($alias->internal_item_id);

            if (!$material) {
                return null;
            }
        }

        return $this->buildFacadeDataFromMaterial($material);
    }

    /**
     * Resolve facade data from a material ID directly.
     */
    private function resolveFacadeFromMaterialId(int $materialId): ?array
    {
        $material = Material::where('type', Material::TYPE_FACADE)->find($materialId);
        if (!$material) {
            return null;
        }
        return $this->buildFacadeDataFromMaterial($material);
    }

    /**
     * Build position facade fields from a Material model.
     */
    private function buildFacadeDataFromMaterial(Material $material): array
    {
        // Get price from active price list version, fall back to material.price_per_unit
        $price = MaterialPrice::where('material_id', $material->id)
            ->whereHas('priceListVersion', function ($q) {
                $q->where('status', PriceListVersion::STATUS_ACTIVE);
            })
            ->orderByDesc('id')
            ->first();

        $metadata = $material->metadata ?? [];

        // Handle both metadata formats: nested (base.material) and flat (base_material)
        $finishType = $metadata['finish']['type'] ?? $metadata['finish_type'] ?? null;
        $finishName = $metadata['finish']['name'] ?? $metadata['finish_name'] ?? null;
        $baseMaterial = $metadata['base']['material'] ?? $metadata['base_material'] ?? null;

        // Sanitize finish_type — must be valid enum or null
        $validFinishTypes = ['pvc_film', 'plastic', 'enamel', 'veneer', 'solid_wood', 'aluminum_frame', 'other'];
        if ($finishType && !in_array($finishType, $validFinishTypes, true)) {
            // Free-text value — move to finishName
            if (!$finishName) {
                $finishName = $finishType;
            }
            $finishType = null;
        }

        $finishLabel = match ($finishType) {
            'pvc_film' => 'ПВХ',
            'plastic' => 'Пластик',
            'enamel' => 'Эмаль',
            'veneer' => 'Шпон',
            'solid_wood' => 'Массив',
            'aluminum_frame' => 'Алюм. рамка',
            default => $finishType ?? '',
        };

        $pricePerM2 = $price ? (float) $price->price_per_internal_unit : ($material->price_per_unit ? (float) $material->price_per_unit : null);

        return [
            'kind' => 'facade',
            'facade_material_id' => $material->id,
            'material_price_id' => $price?->id,
            'base_material_label' => $baseMaterial ? mb_strtoupper($baseMaterial) : null,
            'thickness_mm' => $material->thickness_mm ?? ($metadata['thickness_mm'] ?? null),
            'finish_type' => $finishType,
            'finish_name' => $finishName,
            'decor_label' => trim("{$finishLabel} {$finishName}"),
            'price_per_m2' => $pricePerM2,
            'edge_material_id' => null,
            'edge_scheme' => 'none',
        ];
    }
}
