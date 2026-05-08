<?php

namespace App\Services\Smeta;

use App\Dto\EdgeAggregateDto;
use App\Dto\OperationAggregateDto;
use App\Dto\PlateAggregateDto;
use App\Models\Material;
use App\Models\MaterialPrice;
use App\Models\Operation;
use App\Models\OperationPrice;
use App\Models\Project;
use App\Models\ProjectPosition;
use App\Services\FinishedProductFacadeRevisionRowAssembler;
use App\Services\FinishedProductFacadeSnapshotPresenter;
use App\Services\FinishedProductPositionSnapshotReader;
use App\Services\OperationPricingSummaryService;
use Illuminate\Support\Collection;

/**
 * SmetaCalculator - центральный сервис для расчётов сметы
 * 
 * Обеспечивает единый источник истины для расчётов:
 * - Коэффициенты отходов (плиты, кромка, операции)
 * - Агрегация плитных материалов (площадь, листы, стоимость)
 * - Агрегация кромочных материалов (линейная длина, стоимость)
 * - Итоговые расчёты (subtotal, total)
 * 
 * Цены на операции берутся из pricing summary,
 * НЕ из operations.cost_per_unit (legacy поле).
 */
class SmetaCalculator
{
    protected OperationPricingSummaryService $operationPricingSummaryService;
    protected OperationApplicationResolver $operationApplicationResolver;
    protected FinishedProductPositionSnapshotReader $finishedProductSnapshotReader;
    protected FinishedProductFacadeRevisionRowAssembler $finishedProductFacadeRevisionRowAssembler;
    protected FinishedProductFacadeSnapshotPresenter $finishedProductFacadeSnapshotPresenter;

    public function __construct(
        ?OperationPricingSummaryService $operationPricingSummaryService = null,
        ?OperationApplicationResolver $operationApplicationResolver = null,
        ?FinishedProductPositionSnapshotReader $finishedProductSnapshotReader = null,
        ?FinishedProductFacadeRevisionRowAssembler $finishedProductFacadeRevisionRowAssembler = null,
        ?FinishedProductFacadeSnapshotPresenter $finishedProductFacadeSnapshotPresenter = null
    )
    {
        $this->operationPricingSummaryService = $operationPricingSummaryService ?? app(OperationPricingSummaryService::class);
        $this->operationApplicationResolver = $operationApplicationResolver ?? new OperationApplicationResolver();
        $this->finishedProductSnapshotReader = $finishedProductSnapshotReader ?? app(FinishedProductPositionSnapshotReader::class);
        $this->finishedProductFacadeRevisionRowAssembler = $finishedProductFacadeRevisionRowAssembler ?? app(FinishedProductFacadeRevisionRowAssembler::class);
        $this->finishedProductFacadeSnapshotPresenter = $finishedProductFacadeSnapshotPresenter ?? app(FinishedProductFacadeSnapshotPresenter::class);
    }
    /**
     * Получить коэффициент отходов для плитных материалов
     * С учётом флага apply_waste_to_plate и fallback на общий коэффициент
     */
    public function getWasteCoefficientForPlate(Project $project): float
    {
        $apply = $project->apply_waste_to_plate !== false;
        if (!$apply) {
            return 1.0;
        }

        // Если задан специфичный коэффициент плит - используем его, иначе общий
        if ($project->waste_plate_coefficient) {
            return (float) $project->waste_plate_coefficient;
        }

        return (float) ($project->waste_coefficient ?? 1.0);
    }

    /**
     * Получить коэффициент отходов для кромочных материалов
     * С учётом флага apply_waste_to_edge и fallback на общий коэффициент
     */
    public function getWasteCoefficientForEdge(Project $project): float
    {
        $apply = $project->apply_waste_to_edge !== false;
        if (!$apply) {
            return 1.0;
        }

        // Если задан специфичный коэффициент кромки - используем его, иначе общий
        if ($project->waste_edge_coefficient) {
            return (float) $project->waste_edge_coefficient;
        }

        return (float) ($project->waste_coefficient ?? 1.0);
    }

    /**
     * Получить коэффициент отходов для операций
     * С учётом флага apply_waste_to_operations и fallback на общий коэффициент
     */
    public function getWasteCoefficientForOperations(Project $project): float
    {
        $apply = $project->apply_waste_to_operations !== false;
        if (!$apply) {
            return 1.0;
        }

        // Если задан специфичный коэффициент операций - используем его, иначе общий
        if ($project->waste_operations_coefficient) {
            return (float) $project->waste_operations_coefficient;
        }

        return (float) ($project->waste_coefficient ?? 1.0);
    }

    /**
     * Рассчитать агрегированные данные плитных материалов
     * 
     * @return PlateAggregateDto[]
     */
    public function calculatePlateData(Project $project): array
    {
        try {
            $positions = $project->positions()->with('material', 'detailType')->get();
            if ($positions->isEmpty()) {
                return [];
            }

            $wasteCoeff = $this->getWasteCoefficientForPlate($project);
            $plateMap = [];

            // Собираем все площади по материалам
            foreach ($positions as $position) {
                // Skip facade positions — they are calculated separately
                if ($position->kind === 'facade') {
                    continue;
                }

                if (!$position->material_id) {
                    continue;
                }

                $material = $position->material;
                if (!$material || $material->type !== 'plate') {
                    continue;
                }

                // Площадь одной детали (м²) = ширина × длина (в м)
                $area = (($position->width ?? 0) / 1000) * (($position->length ?? 0) / 1000) * ($position->quantity ?? 0);

                if (!isset($plateMap[$position->material_id])) {
                    $sheetAreaM2 = ($material->length_mm && $material->width_mm)
                        ? ($material->length_mm * $material->width_mm) / 1_000_000
                        : 0;

                    $pricePerM2 = ($material->price_per_unit && $sheetAreaM2 > 0)
                        ? ($material->price_per_unit / $sheetAreaM2)
                        : 0;

                    $plateMap[$position->material_id] = [
                        'id' => $position->material_id,
                        'name' => $material->name,
                        'area_details' => 0,
                        'waste_coeff' => $wasteCoeff,
                        'area_with_waste' => 0,
                        'sheet_area' => $sheetAreaM2,
                        'sheets_count' => 0,
                        'price_per_sheet' => $material->price_per_unit ?? 0,
                        'price_per_m2' => $pricePerM2,
                        'total_cost' => 0,
                        'updated_at' => $material->updated_at,
                        'source_url' => $material->source_url,
                        'position_details' => [],
                    ];
                }

                $plateMap[$position->material_id]['area_details'] += $area;
                
                // Добавляем информацию о позиции
                $plateMap[$position->material_id]['position_details'][] = [
                    'detail_type' => $position->custom_name ?? $position->detailType?->name ?? 'Деталь (без наименования)',
                    'quantity' => $position->quantity ?? 1,
                    'width' => $position->width ?? 0,
                    'length' => $position->length ?? 0,
                    'area' => $area / ($position->quantity ?? 1),  // Площадь одной детали
                ];
            }

            // Пересчитываем с отходами и стоимостью
            $isAreaMode = (bool) $project->use_area_calc_mode;

            foreach ($plateMap as &$entry) {
                $entry['area_with_waste'] = $entry['area_details'] * $wasteCoeff;

                if (!$isAreaMode) {
                    // Режим по листам
                    $entry['sheets_count'] = $entry['sheet_area'] > 0
                        ? ceil($entry['area_with_waste'] / $entry['sheet_area'])
                        : 0;
                    $entry['total_cost'] = $entry['sheets_count'] * $entry['price_per_sheet'];
                } else {
                    // Режим по площади
                    $entry['sheets_count'] = 0;
                    $entry['total_cost'] = $entry['area_with_waste'] * $entry['price_per_m2'];
                }
            }

            // Преобразуем в PlateAggregateDto
            return array_map(fn($entry) => new PlateAggregateDto(
                id: (int) $entry['id'],
                name: $entry['name'],
                area_details: $entry['area_details'],
                waste_coeff: $entry['waste_coeff'],
                area_with_waste: $entry['area_with_waste'],
                sheet_area: $entry['sheet_area'],
                sheets_count: (int) $entry['sheets_count'],
                price_per_sheet: $entry['price_per_sheet'],
                price_per_m2: $entry['price_per_m2'],
                total_cost: $entry['total_cost'],
                updated_at: $entry['updated_at'],
                source_url: $entry['source_url'],
                position_details: $entry['position_details'],
            ), array_values($plateMap));

        } catch (\Exception $e) {
            \Log::error('SmetaCalculator::calculatePlateData error', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return [];
        }
    }

    /**
     * Рассчитать агрегированные данные по фасадам
     *
     * area_m2 = (width_mm/1000) * (length_mm/1000) * quantity
     * total_cost = area_m2 * price_per_m2
     * 
     * @return array[] Each entry: id, name, facade_positions, area_total, price_per_m2, total_cost, ...
     */
    public function calculateFacadeData(Project $project): array
    {
        try {
            $positions = $project->positions()
                ->where('kind', 'facade')
                ->with([
                    'facadeMaterial',
                    'finishedProductSpecification',
                    'priceQuotes.priceListVersion.priceList.supplier',
                    'priceQuotes.supplier',
                    'priceQuotes.materialPrice',
                ])
                ->get();

            if ($positions->isEmpty()) {
                return [];
            }

            $facadeMap = [];

            foreach ($positions as $position) {
                $snapshotFacade = $this->finishedProductSnapshotReader->supports($position)
                    ? $this->finishedProductSnapshotReader->read($position)
                    : null;
                $snapshotPresentation = $snapshotFacade !== null
                    ? $this->finishedProductFacadeSnapshotPresenter->presentFromJustificationSummary(
                        $this->finishedProductFacadeRevisionRowAssembler->buildSnapshotSummary($position)
                    )
                    : null;
                $materialId = $snapshotFacade['group_key'] ?? ('material:' . ($position->facade_material_id ?? 0));
                $material = $position->facadeMaterial;

                $areaM2 = (($position->width ?? 0) / 1000) * (($position->length ?? 0) / 1000) * ($position->quantity ?? 0);
                $pricePerM2 = $snapshotFacade !== null
                    ? (float) ($snapshotFacade['price_per_m2'] ?? 0)
                    : (float) ($position->price_per_m2 ?? 0);
                $totalCost = $areaM2 * $pricePerM2;

                if (!isset($facadeMap[$materialId])) {
                    $facadeMap[$materialId] = [
                        'id' => $snapshotFacade['reference_id'] ?? ($position->facade_material_id ?? 0),
                        'reference_type' => $snapshotFacade['reference_type'] ?? 'material',
                        'name' => $snapshotFacade['name'] ?? $material?->name ?? ($position->decor_label ?? 'Фасад (без материала)'),
                        'article' => $snapshotFacade['article'] ?? null,
                        'decor_label' => $snapshotFacade['decor_label'] ?? $position->decor_label,
                        'base_material_label' => $snapshotFacade['base_material_label'] ?? $position->base_material_label,
                        'thickness_mm' => $snapshotFacade['thickness_mm'] ?? $position->thickness_mm,
                        'finish_type' => $snapshotFacade['finish_type'] ?? $position->finish_type,
                        'finish_type_label' => $snapshotFacade['finish_type_label']
                            ?? (Material::FINISH_LABELS[$position->finish_type] ?? $position->finish_type),
                        'finish_name' => $snapshotFacade['finish_name'] ?? $position->finish_name,
                        'price_per_m2' => $pricePerM2,
                        'area_total' => 0,
                        'total_cost' => 0,
                        'positions_count' => 0,
                        'position_details' => [],
                    ];
                }

                $facadeMap[$materialId]['area_total'] += $areaM2;
                $facadeMap[$materialId]['total_cost'] += $totalCost;
                $facadeMap[$materialId]['positions_count']++;

                $effectiveMethod = $snapshotFacade['price_method'] ?? $position->price_method ?? 'single';
                if ($snapshotFacade === null && $effectiveMethod !== 'single' && $position->priceQuotes->isEmpty()) {
                    $effectiveMethod = 'single';
                }

                $posDetail = [
                    'position_id' => $position->id,
                    'id' => $position->id,
                    'detail_type' => $position->custom_name ?? 'Фасад',
                    'quantity' => $position->quantity ?? 1,
                    'width' => $position->width ?? 0,
                    'length' => $position->length ?? 0,
                    'area_m2' => $areaM2,
                    'price_per_m2' => $pricePerM2,
                    'total_cost' => $totalCost,
                    'price_method' => $effectiveMethod,
                    'price_sources_count' => $effectiveMethod === 'single'
                        ? null
                        : ($snapshotFacade['price_sources_count'] ?? $position->price_sources_count),
                    'price_min' => $snapshotFacade['price_min'] ?? ($position->price_min ? (float) $position->price_min : null),
                    'price_max' => $snapshotFacade['price_max'] ?? ($position->price_max ? (float) $position->price_max : null),
                    'pricing_basis' => $snapshotFacade !== null ? 'finished_product_snapshot' : 'legacy_quote',
                    'pricing_snapshot_captured_at' => $snapshotFacade['captured_at'] ?? null,
                    'pricing_computed_at' => $snapshotFacade['computed_at'] ?? null,
                    'source_level_snapshot' => $snapshotFacade['source_level_snapshot'] ?? null,
                    'facade_snapshot_presentation' => $snapshotPresentation,
                ];

                if ($snapshotPresentation !== null && !empty($snapshotPresentation['quotes'])) {
                    $posDetail['quotes'] = $snapshotPresentation['quotes'];
                }

                if ($snapshotFacade === null && $position->isAggregated() && $position->priceQuotes->isNotEmpty()) {
                    $posDetail['quotes'] = $position->priceQuotes->map(function ($q) {
                        $version = $q->priceListVersion;
                        $priceList = $version?->priceList;
                        $supplier = $q->supplier ?? $priceList?->supplier;
                        $matPrice = $q->materialPrice;
                        return [
                            'price_per_m2' => (float) $q->price_per_m2_snapshot,
                            'price_list_name' => $priceList?->name ?? '—',
                            'version_number' => $version?->version_number,
                            'price_list_version_id' => $version?->id,
                            'source_type' => $version?->source_type,
                            'source_url' => $version?->source_url,
                            'original_filename' => $version?->original_filename,
                            'sha256' => $version?->sha256,
                            'effective_date' => $version?->effective_date?->format('d.m.Y'),
                            'captured_at' => $q->captured_at?->format('d.m.Y H:i'),
                            'supplier_id' => $supplier?->id,
                            'supplier_name' => $supplier?->name ?? '—',
                            'supplier_article' => $matPrice?->article ?? null,
                            'supplier_category' => $matPrice?->category ?? null,
                            'supplier_description' => $matPrice?->description ?? null,
                            'mismatch_flags' => $q->mismatch_flags,
                        ];
                    })->toArray();
                }

                $facadeMap[$materialId]['position_details'][] = $posDetail;
            }

            return array_values($facadeMap);

        } catch (\Exception $e) {
            \Log::error('SmetaCalculator::calculateFacadeData error', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return [];
        }
    }

    /**
     * Рассчитать общую стоимость фасадов
     */
    public function calculateFacadesTotalCost(array $facades): float
    {
        return array_reduce($facades, fn($sum, $f) => $sum + ($f['total_cost'] ?? 0), 0.0);
    }

    /**
     * Рассчитать агрегированные данные кромочных материалов
     * 
     * @return EdgeAggregateDto[]
     */
    public function calculateEdgeData(Project $project): array
    {
        try {
            $positions = $project->positions()->with('edgeMaterial', 'detailType')->get();
            if ($positions->isEmpty()) {
                return [];
            }

            $wasteCoeff = $this->getWasteCoefficientForEdge($project);
            $edgeMap = [];

            foreach ($positions as $position) {
                // Skip facade positions — no edge processing for facades
                if ($position->kind === 'facade') {
                    continue;
                }

                if (!$position->edge_material_id || !$position->edge_scheme || $position->edge_scheme === 'none') {
                    continue;
                }

                $material = $position->edgeMaterial;
                if (!$material || $material->type !== 'edge') {
                    continue;
                }

                // Рассчитываем периметр для обработки (в метрах) в зависимости от схемы
                $perimeterMeters = $this->calculateEdgePerimeter(
                    $position->width ?? 0,
                    $position->length ?? 0,
                    $position->quantity ?? 0,
                    $position->edge_scheme
                );

                if (!isset($edgeMap[$position->edge_material_id])) {
                    $edgeMap[$position->edge_material_id] = [
                        'id' => $position->edge_material_id,
                        'name' => $material->name,
                        'length_linear' => 0,
                        'waste_coeff' => $wasteCoeff,
                        'length_with_waste' => 0,
                        'price_per_unit' => $material->price_per_unit ?? 0,
                        'total_cost' => 0,
                        'updated_at' => $material->updated_at,
                        'source_url' => $material->source_url,
                        'position_details' => [],
                    ];
                }

                $edgeMap[$position->edge_material_id]['length_linear'] += $perimeterMeters;
                
                // Добавляем информацию о позиции
                $edgeMap[$position->edge_material_id]['position_details'][] = [
                    'detail_type' => $position->custom_name ?? $position->detailType?->name ?? 'Деталь (без наименования)',
                    'quantity' => $position->quantity ?? 1,
                    'width' => $position->width ?? 0,
                    'length' => $position->length ?? 0,
                    'scheme' => $position->edge_scheme,
                    'perimeter' => $perimeterMeters / ($position->quantity ?? 1),  // Периметр одной детали
                    'length_total' => $perimeterMeters,  // Общая длина кромки для этой позиции
                ];
            }

            // Добавляем расчет длины с отходами и стоимостью
            foreach ($edgeMap as &$entry) {
                $entry['length_with_waste'] = $entry['length_linear'] * $wasteCoeff;
                $entry['total_cost'] = $entry['length_with_waste'] * $entry['price_per_unit'];
            }

            // Преобразуем в EdgeAggregateDto
            return array_map(fn($entry) => new EdgeAggregateDto(
                id: (int) $entry['id'],
                name: $entry['name'],
                length_linear: $entry['length_linear'],
                waste_coeff: $entry['waste_coeff'],
                length_with_waste: $entry['length_with_waste'],
                price_per_unit: $entry['price_per_unit'],
                total_cost: $entry['total_cost'],
                updated_at: $entry['updated_at'],
                source_url: $entry['source_url'],
                position_details: $entry['position_details'],
            ), array_values($edgeMap));

        } catch (\Exception $e) {
            \Log::error('SmetaCalculator::calculateEdgeData error', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return [];
        }
    }

    /**
     * Вспомогательный метод - рассчитать периметр кромки по схеме
     * 
     * @param float $widthMm Ширина детали (мм)
     * @param float $lengthMm Длина детали (мм)
     * @param int $quantity Количество
     * @param string $scheme Схема кромки (O, =, ||, L, П, long_one, short_one)
     * @return float Периметр в метрах
     */
    private function calculateEdgePerimeter(float $widthMm, float $lengthMm, int $quantity, string $scheme): float
    {
        $widthM = $widthMm / 1000;
        $lengthM = $lengthMm / 1000;

        return match ($scheme) {
            'O' => 2 * ($widthM + $lengthM) * $quantity,              // Вкруг
            '=' => 2 * $lengthM * $quantity,                           // Параллельно длине
            '||' => 2 * $widthM * $quantity,                           // Параллельно ширине
            'L' => ($widthM + $lengthM) * $quantity,                   // Г-образно
            'П' => (2 * $widthM + $lengthM) * $quantity,               // П-образно
            'long_one' => $lengthM * $quantity,                        // Одна длинная сторона
            'short_one' => $widthM * $quantity,                        // Одна короткая сторона
            default => 0,
        };
    }

    /**
     * Рассчитать итоговые стоимости
     * 
     * @param PlateAggregateDto[] $plates
     * @param EdgeAggregateDto[] $edges
     * @param array $facades Facade aggregate data
     * @return array{
     *     plates_total_cost: float,
     *     edges_total_cost: float,
     *     facades_total_cost: float,
     *     materials_total_cost: float,
     * }
     */
    public function calculateMaterialsTotals(array $plates, array $edges, array $facades = []): array
    {
        $platesTotalCost = array_reduce($plates, fn($sum, $p) => $sum + $p->total_cost, 0.0);
        $edgesTotalCost = array_reduce($edges, fn($sum, $e) => $sum + $e->total_cost, 0.0);
        $facadesTotalCost = $this->calculateFacadesTotalCost($facades);

        return [
            'plates_total_cost' => $platesTotalCost,
            'edges_total_cost' => $edgesTotalCost,
            'facades_total_cost' => $facadesTotalCost,
            'materials_total_cost' => $platesTotalCost + $edgesTotalCost + $facadesTotalCost,
        ];
    }

    /**
     * Рассчитать агрегированные данные операций
     * 
     * Включает:
     * 1. Операции из detail_type позиций
     * 2. Автоматические операции резки материалов (раскрой ДСП)
     * 3. Автоматические операции кромкооблицовки
     * 4. Ручные операции из project_manual_operations
     * 
     * Автоматические операции берутся только из OperationApplicationResolver.
     * Legacy cutting / edging fallback удалён.
     *
     * ВАЖНО: Цены берутся из pricing summary,
     * НЕ из operations.cost_per_unit (это legacy поле)!
     * 
     * @param Project $project
     * @param string|null $priceMode Режим цены: 'by_supplier' или 'median' (null = default)
     * @param int|null $supplierId ID поставщика (для режима by_supplier)
     * @return OperationAggregateDto[]
     */
    public function calculateOperationData(
        Project $project,
        ?string $priceMode = null,
        ?int $supplierId = null
    ): array {
        try {
            $positions = $project->positions()->with(['material', 'edgeMaterial', 'detailType'])->get();
            $operationsMap = [];
            $applicationRules = $this->operationApplicationResolver->loadRulesForProject($project);
            $applicationRuleRows = $this->resolveApplicationRows($project, $positions, $applicationRules);
            
            // 1. Собираем операции из detail_type позиций
            foreach ($positions as $position) {
                if (!$position->detail_type_id || !$position->detailType) {
                    continue;
                }
                
                $detailType = $position->detailType;
                foreach ($this->resolveDetailTypeOperationRows($detailType) as $dto) {
                    $operation = $dto->operation;
                    if (!$operation) {
                        continue;
                    }
                    
                    // Расчитываем количество из формулы (если есть)
                    $qty = $this->evaluateFormula($dto->quantity_formula ?? '1', $position) * ($position->quantity ?? 1);
                    
                    $key = 'detail_type_' . $operation->id;
                    if (!isset($operationsMap[$key])) {
                        $operationsMap[$key] = [
                            'operation_id' => $operation->id,
                            'name' => $operation->name ?? '',
                            'category' => $operation->category ?? '',
                            'unit' => $operation->unit ?? 'шт',
                            'quantity' => 0,
                            'is_manual' => false,
                            'updated_at' => $operation->updated_at?->toDateTimeString(),
                            'source_url' => $operation->origin === 'user' ? null : 'system',
                        ];
                    }
                    $operationsMap[$key]['quantity'] += $qty;
                }
            }

            foreach ($applicationRuleRows as $row) {
                $operation = $row['operation'];
                $key = 'application_rule_' . $row['rule_id'] . '_' . $operation->id;

                if (!isset($operationsMap[$key])) {
                    $operationsMap[$key] = [
                        'operation_id' => $operation->id,
                        'name' => $operation->name ?? '',
                        'category' => $operation->category ?? '',
                        'unit' => $row['pricing_unit'] ?? ($operation->unit ?? 'м²'),
                        'quantity' => 0,
                        'is_manual' => false,
                        'updated_at' => $operation->updated_at?->toDateTimeString(),
                        'source_url' => $operation->origin === 'user' ? null : 'system',
                        'application_rule_id' => $row['rule_id'],
                        'quantity_source' => $row['quantity_source'],
                        'pricing_unit' => $row['pricing_unit'],
                        'tariff_binding_type' => $row['tariff_binding_type'],
                        'tariff_operation_id' => $row['tariff_operation_id'] ?? $operation->id,
                        'context' => $row['context'] ?? [],
                    ];
                }

                $operationsMap[$key]['quantity'] += $row['quantity'];
            }
            
            // 4. Загружаем manual operations с операциями
            $manualOperations = $project->manualOperations()
                ->with('operation')
                ->get();

            foreach ($manualOperations as $manualOp) {
                $operation = $manualOp->operation;
                if (!$operation) {
                    continue;
                }

                $quantity = $manualOp->quantity ?? 0;

                $key = 'manual_' . $manualOp->id;
                if (!isset($operationsMap[$key])) {
                    $operationsMap[$key] = [
                        'operation_id' => $operation->id,
                        'name' => $operation->name ?? '',
                        'category' => $operation->category ?? '',
                        'unit' => $operation->unit ?? 'шт',
                        'quantity' => $quantity,
                        'is_manual' => true,
                        'updated_at' => $operation->updated_at?->toDateTimeString(),
                        'source_url' => $operation->origin === 'user' ? null : 'system',
                    ];
                } else {
                    $operationsMap[$key]['quantity'] += $quantity;
                }
            }

            // Получаем все ID тарифных операций для batch запроса pricing summaries
            $operationIds = array_values(array_unique(array_map(
                fn (array $entry) => (int) ($entry['tariff_operation_id'] ?? $entry['operation_id']),
                $operationsMap,
            )));
            $pricingSummaries = $this->operationPricingSummaryService->getSummariesForOperations(
                $operationIds,
                $project,
            );

            // Преобразуем в OperationAggregateDto и рассчитываем стоимость
            $operationDtos = [];
            foreach ($operationsMap as $entry) {
                $tariffOperationId = (int) ($entry['tariff_operation_id'] ?? $entry['operation_id']);
                $pricingSummary = $pricingSummaries[$tariffOperationId] ?? null;
                $effectivePrice = $pricingSummary['effective_price'] ?? null;
                $effectiveSource = $pricingSummary['effective_source'] ?? null;
                $costPerUnit = $effectivePrice !== null ? (float) $effectivePrice : 0.0;
                $pricingUnit = $entry['pricing_unit'] ?? $entry['unit'] ?? null;
                $resolvedPriceUnit = $effectiveSource['unit'] ?? null;
                $unitMismatch = $this->hasUnitMismatch($pricingUnit, $resolvedPriceUnit);
                $isValid = $effectivePrice !== null && !$unitMismatch;
                $totalCost = $isValid ? round($entry['quantity'] * $costPerUnit, 2) : null;
                
                $operationDto = new OperationAggregateDto(
                    id: $entry['operation_id'],
                    name: $entry['name'],
                    category: $entry['category'],
                    unit: $entry['unit'],
                    cost_per_unit: $costPerUnit,
                    quantity: $entry['quantity'],
                    total_cost: $totalCost,
                    is_manual: $entry['is_manual'],
                    updated_at: $entry['updated_at'],
                    source_url: $entry['source_url'],
                    is_valid: $isValid,
                    unit_mismatch: $unitMismatch,
                    pricing_unit: $pricingUnit,
                    resolved_price_unit: $resolvedPriceUnit,
                );
                
                $operationDtos[] = $operationDto;
            }

            return $operationDtos;
        } catch (\Exception $e) {
            \Log::error('SmetaCalculator::calculateOperationData() error', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return [];
        }
    }

    /**
     * Вспомогательный метод для оценки формулы количества
     * Базовая реализация - всегда возвращает 1
     */
    private function evaluateFormula(?string $formula, ProjectPosition $position): float
    {
        if (!$formula) {
            return 1.0;
        }
        
        // Простая реализация - можно расширить при необходимости
        try {
            // Если формула - это просто число
            if (is_numeric($formula)) {
                return (float) $formula;
            }
            return match (trim($formula)) {
                'perimeter_m' => (($position->width + $position->length) * 2) / 1000.0,
                'area_m2' => (($position->width ?? 0) * ($position->length ?? 0)) / 1_000_000.0,
                default => 1.0,
            };
        } catch (\Exception $e) {
            return 1.0;
        }
    }

    private function resolveDetailTypeOperationRows($detailType): array
    {
        $rows = $detailType->detailTypeOperations()
            ->with('operation')
            ->get()
            ->filter(fn ($row) => $row->operation !== null)
            ->values()
            ->all();

        if (!empty($rows)) {
            return $rows;
        }

        $components = is_array($detailType->components) ? $detailType->components : [];
        if (empty($components)) {
            return [];
        }

        $operationIds = array_values(array_unique(array_filter(array_map(
            fn ($component) => (int) ($component['operation_id'] ?? $component['id'] ?? 0),
            $components
        ))));
        if (empty($operationIds)) {
            return [];
        }

        $operations = Operation::whereIn('id', $operationIds)->get()->keyBy('id');
        $fallbackRows = [];
        $seen = [];

        foreach ($components as $component) {
            $operationId = (int) ($component['operation_id'] ?? $component['id'] ?? 0);
            if ($operationId <= 0 || isset($seen[$operationId]) || !isset($operations[$operationId])) {
                continue;
            }

            $seen[$operationId] = true;
            $fallbackRows[] = (object) [
                'operation' => $operations[$operationId],
                'quantity_formula' => (string) ($component['quantity'] ?? '1'),
            ];
        }

        return $fallbackRows;
    }

    private function resolveApplicationRows(Project $project, Collection $positions, Collection $rules): array
    {
        if ($rules->isEmpty()) {
            return [];
        }

        $rows = $this->operationApplicationResolver->resolve($project, $positions, $rules);

        return array_values(array_filter(array_map(function (array $row) use ($rules) {
            $rule = $rules->firstWhere('id', $row['rule_id']);
            $operation = $rule?->operation;

            if (!$operation) {
                return null;
            }

            return [
                ...$row,
                'operation' => $operation,
            ];
        }, $rows)));
    }

    /**
     * Рассчитать итоговую стоимость операций
     * 
     * @return float
     */
    public function calculateOperationsTotalCost(array $operations): float
    {
        return array_reduce(
            $operations,
            fn($sum, OperationAggregateDto $op) => $sum + ($op->is_valid ? ($op->total_cost ?? 0.0) : 0.0),
            0.0
        );
    }

    /**
     * Рассчитать агрегированные данные фиттингов
     * 
     * Берём из project_fittings и рассчитываем total_cost = quantity * unit_price
     * 
     * @return array<FittingDto>
     */
    public function calculateFittingsData(Project $project): array
    {
        try {
            $fittings = $project->fittings()->get();

            if ($fittings->isEmpty()) {
                return [];
            }

            $fittingDtos = [];

            foreach ($fittings as $fitting) {
                $totalCost = ($fitting->quantity ?? 0) * ($fitting->unit_price ?? 0);

                $fittingDto = new \App\Dto\FittingDto(
                    id: $fitting->id,
                    name: $fitting->name ?? '',
                    article: $fitting->article ?? '',
                    unit: $fitting->unit ?? 'шт',
                    quantity: (float) ($fitting->quantity ?? 0),
                    unit_price: (float) ($fitting->unit_price ?? 0),
                    total_cost: $totalCost,
                );

                $fittingDtos[] = $fittingDto;
            }

            return $fittingDtos;
        } catch (\Exception $e) {
            \Log::error('SmetaCalculator::calculateFittingsData() error', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Рассчитать итоговую стоимость фиттингов
     * 
     * @return float
     */
    public function calculateFittingsTotalCost(array $fittings): float
    {
        return array_reduce(
            $fittings,
            fn($sum, $f) => $sum + $f->total_cost,
            0.0
        );
    }

    /**
     * Рассчитать агрегированные данные расходов
     * 
     * Берём из expenses и передаём как есть
     * 
     * @return array<ExpenseDto>
     */
    public function calculateExpensesData(Project $project): array
    {
        try {
            $expenses = $project->expenses()->get();

            if ($expenses->isEmpty()) {
                return [];
            }

            $expenseDtos = [];

            foreach ($expenses as $expense) {
                $expenseDto = new \App\Dto\ExpenseDto(
                    id: $expense->id,
                    type: $expense->name ?? '',
                    cost: (float) ($expense->amount ?? 0),
                    description: $expense->description ?? null,
                );

                $expenseDtos[] = $expenseDto;
            }

            return $expenseDtos;
        } catch (\Exception $e) {
            \Log::error('SmetaCalculator::calculateExpensesData() error', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Рассчитать итоговую стоимость расходов
     * 
     * @return float
     */
    public function calculateExpensesTotalCost(array $expenses): float
    {
        return array_reduce(
            $expenses,
            fn($sum, $e) => $sum + $e->cost,
            0.0
        );
    }

    private function hasUnitMismatch(?string $pricingUnit, ?string $resolvedPriceUnit): bool
    {
        $expectedUnit = OperationPrice::normalizeUnit($pricingUnit);
        $actualUnit = OperationPrice::normalizeUnit($resolvedPriceUnit);

        return $expectedUnit !== null
            && $actualUnit !== null
            && $expectedUnit !== $actualUnit;
    }
}

