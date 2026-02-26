<?php

namespace App\Service;

use App\Dto\ExpenseDto;
use App\Dto\FittingDto;
use App\Dto\LaborWorkDto;
use App\Dto\MaterialDto;
use App\Dto\MaterialsDto;
use App\Dto\OperationDto;
use App\Dto\OperationAggregateDto;
use App\Dto\PositionDto;
use App\Dto\ProjectMetaDto;
use App\Dto\ReportDto;
use App\Dto\TotalsDto;
use App\Models\PositionProfile;
use App\Models\Project;
use App\Models\ProjectPosition;
use App\Services\ProjectProfileRateResolver;
use App\Services\RateModelCalculator;
use App\Services\Smeta\SmetaCalculator;

class ReportService
{
    public function __construct(
        private SmetaCalculator $calculator,
        private ProjectProfileRateResolver $rateResolver,
        private ?RateModelCalculator $rateModelCalculator = null,
    ) {
        $this->rateModelCalculator = $rateModelCalculator ?? new RateModelCalculator();
    }

    /**
     * Построить полный отчёт для проекта
     * 
     * Используется единый источник истины - SmetaCalculator
     * для расчётов плит, кромок и totals
     */
    public function buildReport(Project $project): ReportDto
    {
        // Убедиться что регион проекта загружен (для использования в justifications)
        if (!$project->relationLoaded('region')) {
            $project->load('region');
        }
        
        // 1. Подготовить мета-информацию проекта (включая источники нормо-часов)
        $projectMeta = $this->buildProjectMeta($project);

        // 2. Загрузить все позиции с материалом и типом детали
        $positions = $project->positions()->with(['detailType', 'material', 'facadeMaterial'])->get();

        // 3. Подготовить позиции
        $positionDtos = [];
        $operationsMap = [];
        
        foreach ($positions as $position) {
            $positionDto = $this->buildPosition($position);
            $positionDtos[] = $positionDto;
            $this->collectOperations($positionDto, $operationsMap);
        }

        // 4. Рассчитать плиты, кромки и фасады используя SmetaCalculator
        $plates = $this->calculator->calculatePlateData($project);
        $edges = $this->calculator->calculateEdgeData($project);
        $facades = $this->calculator->calculateFacadeData($project);
        
        // 4b. Рассчитать операции используя SmetaCalculator с ценами поставщика
        // Используем 'snapshot' режим и supplier_id из проекта
        $operations = $this->calculator->calculateOperationData(
            $project,
            priceMode: 'snapshot',
            supplierId: $project->supplier_id ?? null
        );
        $operations = $this->aggregateOperationsForReport($operations);

        // 5. Загрузить фиттинги и расходы
        $fittingDtos = $this->calculator->calculateFittingsData($project);
        $expenseDtos = $this->calculator->calculateExpensesData($project);

        // 6. Загрузить монтажно-сборочные работы
        $laborWorkDtos = $this->buildLaborWorks($project);

        // 7. Рассчитать totals с учётом рассчитанных плит, кромок, фасадов и операций
        $totals = $this->calculateTotals($plates, $edges, $operations, $fittingDtos, $expenseDtos, $laborWorkDtos, $facades);

        // 8. Построить обоснования расчётов ставок по профилям
        $profileRateJustifications = $this->buildProfileRateJustifications($project, $laborWorkDtos);

        // 9. Собрать источники ценовых данных (из project_price_list_versions)
        $priceSources = $this->buildPriceSources($project);

        return new ReportDto(
            project: $projectMeta,
            positions: $positionDtos,
            plates: $plates,
            edges: $edges,
            facades: $facades,
            materials: new MaterialsDto(),
            operations: $operations,
            fittings: $fittingDtos,
            expenses: $expenseDtos,
            labor_works: $laborWorkDtos,
            totals: $totals,
            profile_rate_justifications: $profileRateJustifications,
            price_sources: $priceSources,
        );
    }

    private function buildProjectMeta(Project $project): ProjectMetaDto
    {
        // Загружаем источники нормо-часов
        $normohourSources = $project->normohourSources()
            ->orderBy('sort_order')
            ->get()
            ->map(fn($source) => [
                'id' => $source->id,
                'name' => $source->name,
                'rate' => $source->rate,
                'sort_order' => $source->sort_order,
            ])
            ->toArray();

        return new ProjectMetaDto(
            id: $project->id,
            number: $project->number,
            expert_name: $project->expert_name,
            address: $project->address,
            waste_coefficient: $project->waste_coefficient,
            repair_coefficient: $project->repair_coefficient,
            waste_plate_coefficient: $project->waste_plate_coefficient,
            waste_edge_coefficient: $project->waste_edge_coefficient,
            waste_operations_coefficient: $project->waste_operations_coefficient,
            apply_waste_to_plate: $project->apply_waste_to_plate ?? false,
            apply_waste_to_edge: $project->apply_waste_to_edge ?? false,
            apply_waste_to_operations: $project->apply_waste_to_operations ?? false,
            use_area_calc_mode: $project->use_area_calc_mode ?? false,
            default_plate_material_id: $project->default_plate_material_id,
            default_edge_material_id: $project->default_edge_material_id,
            text_blocks: $project->text_blocks,
            waste_plate_description: $project->waste_plate_description,
            show_waste_plate_description: $project->show_waste_plate_description ?? false,
            waste_edge_description: $project->waste_edge_description,
            show_waste_edge_description: $project->show_waste_edge_description ?? false,
            waste_operations_description: $project->waste_operations_description,
            show_waste_operations_description: $project->show_waste_operations_description ?? false,
            normohour_rate: $project->normohour_rate,
            normohour_region: $project->normohour_region,
            normohour_date: $project->normohour_date ? $project->normohour_date->format('Y-m-d') : null,
            normohour_method: $project->normohour_method,
            normohour_justification: $project->normohour_justification,
            normohour_sources: $normohourSources,
        );
    }

    private function buildPosition(ProjectPosition $position): PositionDto
    {
        // Загружаем материал и тип детали
        $material = $position->material ? [
            'id' => $position->material->id,
            'name' => $position->material->name,
        ] : null;
        
        $detailType = $position->detailType ? [
            'id' => $position->detailType->id,
            'name' => $position->detailType->name,
        ] : null;
        
        // Resolve facade material name
        $facadeMaterialName = null;
        if ($position->kind === 'facade') {
            $facadeMaterialName = $position->facadeMaterial?->name
                ?? $position->decor_label
                ?? null;
        }

        return new PositionDto(
            id: $position->id,
            project_id: $position->project_id,
            detail_type_id: $position->detail_type_id,
            material_id: $position->material_id,
            edge_material_id: $position->edge_material_id,
            edge_scheme: $position->edge_scheme,
            quantity: $position->quantity,
            width: $position->width,
            length: $position->length,
            height: $position->height,
            detail_name: $position->custom_name,
            material: $material,
            detail_type: $detailType,
            custom_operations: $position->custom_operations ?? null,
            kind: $position->kind ?? 'panel',
            facade_material_name: $facadeMaterialName,
            materials: [],
            operations: [],
        );
    }

    private function collectMaterials(PositionDto $position, MaterialsDto $materialsMap): void
    {
        // TODO: собрать материалы из позиции и добавить в карту
        // На данный момент позиции пусты
    }

    private function collectOperations(PositionDto $position, array &$operationsMap): void
    {
        // TODO: собрать операции из позиции
        // На данный момент позиции пусты
    }

    /**
     * Агрегировать одинаковые операции для компактного вывода в PDF:
     * складываем количество и итоговую сумму по ключу (id + name + unit + цена за ед.).
     *
     * @param array<int, OperationAggregateDto> $operations
     * @return array<int, OperationAggregateDto>
     */
    private function aggregateOperationsForReport(array $operations): array
    {
        if (empty($operations)) {
            return [];
        }

        $grouped = [];

        foreach ($operations as $operation) {
            if (!($operation instanceof OperationAggregateDto)) {
                continue;
            }

            $idPart = $operation->id ?? 0;
            $pricePart = number_format((float) $operation->cost_per_unit, 6, '.', '');
            $key = implode('|', [
                (string) $idPart,
                mb_strtolower(trim((string) $operation->name), 'UTF-8'),
                trim((string) $operation->unit),
                $pricePart,
            ]);

            if (!isset($grouped[$key])) {
                $grouped[$key] = new OperationAggregateDto(
                    id: $operation->id,
                    name: $operation->name,
                    category: $operation->category,
                    unit: $operation->unit,
                    cost_per_unit: (float) $operation->cost_per_unit,
                    quantity: 0.0,
                    total_cost: 0.0,
                    is_manual: $operation->is_manual,
                    updated_at: $operation->updated_at,
                    source_url: $operation->source_url,
                );
            }

            $grouped[$key]->quantity += (float) $operation->quantity;
            $grouped[$key]->total_cost += (float) $operation->total_cost;
            $grouped[$key]->is_manual = $grouped[$key]->is_manual || $operation->is_manual;
        }

        return array_values($grouped);
    }

    /**
     * Построить список монтажно-сборочных работ с расчётом стоимости
     */
    private function buildLaborWorks(Project $project): array
    {
        $laborWorks = $project->laborWorks()
            ->with('steps')  // Загрузить подоперации
            ->orderBy('sort_order')
            ->get();

        return $laborWorks->map(function($work) use ($project) {
            // Получить ставку для работы (либо из профиля, либо дефолтную ставку проекта)
            $ratePerHour = $work->rate_per_hour ?? $project->normohour_rate ?? 0;
            
            $cost = null;
            if ($ratePerHour > 0) {
                $cost = round($work->hours * $ratePerHour, 2);
            }

            // Подготовить подоперации
            $steps = $work->steps()
                ->orderBy('sort_order')
                ->get()
                ->map(fn($step) => [
                    'id' => $step->id,
                    'title' => $step->title,
                    'basis' => $step->basis,
                    'input_data' => $step->input_data,
                    'hours' => $step->hours,
                    'note' => $step->note,
                    'sort_order' => $step->sort_order,
                ])
                ->toArray();

            return new LaborWorkDto(
                id: $work->id,
                project_id: $work->project_id,
                title: $work->title,
                basis: $work->basis,
                hours: $work->hours,
                note: $work->note,
                sort_order: $work->sort_order,
                project_profile_rate_id: $work->project_profile_rate_id,
                rate_per_hour: $ratePerHour,
                cost: $cost,
                steps: $steps,
            );
        })->toArray();
    }

    private function calculateTotals(
        array $plates,           // PlateAggregateDto[]
        array $edges,            // EdgeAggregateDto[]
        array $operations,       // OperationAggregateDto[]
        array $fittings,         // FittingDto[]
        array $expenses,         // ExpenseDto[]
        array $laborWorks = [],  // LaborWorkDto[]
        array $facades = [],     // Facade aggregate data
    ): TotalsDto {
        $totals = new TotalsDto();

        // Суммировать плиты
        foreach ($plates as $plate) {
            $totals->materials_cost += $plate->total_cost;
        }

        // Суммировать кромки
        foreach ($edges as $edge) {
            $totals->materials_cost += $edge->total_cost;
        }

        // Суммировать фасады
        foreach ($facades as $facade) {
            $totals->materials_cost += $facade['total_cost'] ?? 0;
        }

        // Суммировать операции
        foreach ($operations as $operation) {
            $totals->operations_cost += $operation->total_cost;
        }

        // Суммировать фиттинги
        foreach ($fittings as $fitting) {
            $totals->fittings_cost += $fitting->total_cost;
        }

        // Суммировать расходы
        foreach ($expenses as $expense) {
            $totals->expenses_cost += $expense->cost;
        }

        // Суммировать монтажно-сборочные работы
        $laborWorks_total = 0;
        foreach ($laborWorks as $work) {
            if ($work instanceof LaborWorkDto) {
                $laborWorks_total += $work->cost ?? 0;
            } elseif (isset($work['cost'])) {
                $laborWorks_total += $work['cost'] ?? 0;
            }
        }
        $totals->labor_works_cost = $laborWorks_total;

        // Расчёт итогов
        // grand_total = materials_cost + operations_cost + fittings_cost + expenses_cost + labor_works_cost
        $totals->subtotal = $totals->materials_cost + $totals->operations_cost + 
                           $totals->fittings_cost + $totals->expenses_cost + $totals->labor_works_cost;
        $totals->total = $totals->subtotal;
        $totals->grand_total = $totals->subtotal;

        return $totals;
    }

    /**
     * Построить обоснования расчётов ставок по профилям
     * 
     * Работает в двух режимах:
     * 1. Если есть сохранённые ProfileRates - берёт данные из них (фиксированные ставки)
     * 2. Если нет - строит preview-обоснования на основе профилей работ
     */
    private function buildProfileRateJustifications(Project $project, array $laborWorkDtos): array
    {
        // Убедиться что регион проекта загружен
        if (!$project->relationLoaded('region')) {
            $project->load('region');
        }

        // Загружаем профильные ставки с relations
        $profileRates = $project->profileRates()
            ->with(['profile', 'region'])
            ->orderBy('created_at')
            ->get();

        // Если есть сохранённые профильные ставки - используем их
        if ($profileRates->count() > 0) {
            return $this->buildJustificationsFromSavedRates($project, $profileRates, $laborWorkDtos);
        }

        // Иначе строим preview-обоснования из работ
        return $this->buildJustificationsFromPreview($project, $laborWorkDtos);
    }

    /**
     * Построить обоснования из сохранённых ProfileRates
     */
    private function buildJustificationsFromSavedRates(Project $project, $profileRates, array $laborWorkDtos): array
    {
        $justifications = [];

        foreach ($profileRates as $profileRate) {
            // Получить профиль
            $profileName = $profileRate->profile?->name ?? 'Неизвестный профиль';
            
            // Определить регион
            $regionName = $this->resolveRegionName($profileRate, $project);

            // Получить работы, использующие эту ставку
            $worksUsingThisRate = array_filter($laborWorkDtos, function($work) use ($profileRate) {
                if (!($work instanceof LaborWorkDto)) {
                    return false;
                }
                // 1) Предпочтительно: связь по project_profile_rate_id
                if (!empty($work->project_profile_rate_id)) {
                    return (int) $work->project_profile_rate_id === (int) $profileRate->id;
                }
                // 2) Fallback: сравнение по ставке
                $workRate = (float) ($work->rate_per_hour ?? 0);
                $profileRateValue = (float) ($profileRate->rate_fixed ?? 0);
                return abs($workRate - $profileRateValue) < 0.01;
            });

            if (count($worksUsingThisRate) > 0) {
                // Извлечь данные модели ставки из justification_snapshot
                $justSnapshot = $profileRate->justification_snapshot;
                if (is_string($justSnapshot)) {
                    $justSnapshot = json_decode($justSnapshot, true) ?? [];
                }
                $justSnapshot = $justSnapshot ?? [];

                $justification = $this->buildJustificationEntry(
                    profileName: $profileName,
                    regionName: $regionName,
                    rate: $profileRate->rate_fixed,
                    date: $profileRate->fixed_at ? $profileRate->fixed_at->format('d.m.Y') : null,
                    calculationMethod: $profileRate->calculation_method ?? 'median',
                    sourcesSnapshot: $profileRate->sources_snapshot,
                    justificationSnapshot: $profileRate->justification_snapshot,
                    works: $worksUsingThisRate,
                    rateModel: $justSnapshot['rate_model'] ?? 'labor',
                    baseRate: $justSnapshot['base_rate'] ?? null,
                    modelParams: $justSnapshot['model_params'] ?? null,
                    modelBreakdown: $justSnapshot['model_breakdown'] ?? null,
                );
                $justifications[] = $justification;
            }
        }

        return $justifications;
    }

    /**
     * Построить preview-обоснования из работ (когда нет сохранённых ставок)
     */
    private function buildJustificationsFromPreview(Project $project, array $laborWorkDtos): array
    {
        $justifications = [];

        // Группируем работы по position_profile_id
        $worksByProfile = [];
        foreach ($laborWorkDtos as $work) {
            if (!($work instanceof LaborWorkDto)) {
                continue;
            }
            // Получаем profile_id из работы в БД
            $laborWork = \App\Models\ProjectLaborWork::find($work->id);
            $profileId = $laborWork?->position_profile_id ?? null;
            
            if ($profileId) {
                if (!isset($worksByProfile[$profileId])) {
                    $worksByProfile[$profileId] = [];
                }
                $worksByProfile[$profileId][] = $work;
            }
        }

        // Если нет работ с профилями - пробуем взять все работы как один профиль "Столяр" (profile_id=1)
        if (empty($worksByProfile) && !empty($laborWorkDtos)) {
            $worksByProfile[1] = $laborWorkDtos;
        }

        // Для каждого профиля получаем preview-ставку
        foreach ($worksByProfile as $profileId => $works) {
            try {
                // Получить профиль
                $profile = PositionProfile::find($profileId);
                $profileName = $profile?->name ?? 'Неизвестный профиль';

                // Получить preview-ставку через resolver
                $rateDto = $this->rateResolver->resolveEffectiveRate(
                    $project->id,
                    $profileId,
                    $project->region_id,
                    true  // forcePreview = true
                );

                // Определить регион
                $regionName = $project->region?->region_name ?? 'Не указан';

                // Парсим snapshots из RateDTO
                $sourcesSnapshot = $rateDto->sources_snapshot ?? null;
                if (is_string($sourcesSnapshot)) {
                    $sourcesSnapshot = json_decode($sourcesSnapshot, true);
                }

                $justificationSnapshot = $rateDto->justification_snapshot ?? null;
                if (is_string($justificationSnapshot)) {
                    $justificationSnapshot = json_decode($justificationSnapshot, true);
                }

                // Извлечь данные модели ставки из justification_snapshot (уже заполнено resolver'ом)
                $justSnapshotData = $justificationSnapshot ?? [];
                $rateModel = $justSnapshotData['rate_model'] ?? ($profile ? ($profile->rate_model ?? 'labor') : 'labor');
                $baseRate = $justSnapshotData['base_rate'] ?? $rateDto->rate_per_hour;
                $modelParams = $justSnapshotData['model_params'] ?? ($profile ? $profile->getRateModelParams() : null);
                $modelBreakdown = $justSnapshotData['model_breakdown'] ?? null;

                // Если нет breakdown в justification, вычислить на лету
                if ($modelBreakdown === null && $profile && $rateModel === 'contractor') {
                    $calcResult = $this->rateModelCalculator->calculate($baseRate, $rateModel, $modelParams);
                    $modelBreakdown = $calcResult['breakdown'];
                }

                $justification = $this->buildJustificationEntry(
                    profileName: $profileName,
                    regionName: $regionName,
                    rate: $rateDto->rate_per_hour,
                    date: date('d.m.Y'),  // Preview всегда текущая дата
                    calculationMethod: $rateDto->rate_snapshot['method'] ?? 'median',
                    sourcesSnapshot: $sourcesSnapshot,
                    justificationSnapshot: $justificationSnapshot,
                    works: $works,
                    isPreview: true,
                    rateModel: $rateModel,
                    baseRate: $baseRate,
                    modelParams: $modelParams,
                    modelBreakdown: $modelBreakdown,
                );
                
                $justifications[] = $justification;
                
            } catch (\Exception $e) {
                \Log::warning('Failed to build preview justification', [
                    'profile_id' => $profileId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $justifications;
    }

    /**
     * Определить название региона из ProfileRate или Project
     */
    private function resolveRegionName($profileRate, Project $project): string
    {
        // Проверяем есть ли у profileRate явно установленный регион
        if ($profileRate->region_id) {
            if (!$profileRate->relationLoaded('region')) {
                $profileRate->load('region');
            }
            if ($profileRate->region?->region_name) {
                return $profileRate->region->region_name;
            }
        }

        // Если у profileRate нет региона, берем из настроек проекта
        if ($project->region_id) {
            if (!$project->relationLoaded('region')) {
                $project->load('region');
            }
            if ($project->region?->region_name) {
                return $project->region->region_name;
            }
        }

        return 'Не указан';
    }

    /**
     * Построить запись обоснования
     */
    private function buildJustificationEntry(
        string $profileName,
        string $regionName,
        float $rate,
        ?string $date,
        string $calculationMethod,
        $sourcesSnapshot,
        $justificationSnapshot,
        array $works,
        bool $isPreview = false,
        string $rateModel = 'labor',
        ?float $baseRate = null,
        ?array $modelParams = null,
        ?array $modelBreakdown = null,
    ): array {
        // Парсим sources_snapshot если это JSON-строка
        if (is_string($sourcesSnapshot)) {
            $sourcesSnapshot = json_decode($sourcesSnapshot, true) ?? [];
        }
        $sourcesSnapshot = $sourcesSnapshot ?? [];

        // Парсим justification_snapshot если это JSON-строка
        if (is_string($justificationSnapshot)) {
            $justificationSnapshot = json_decode($justificationSnapshot, true) ?? [];
        }
        $justificationSnapshot = $justificationSnapshot ?? [];

        // Подготовить статистику по источникам из sources_snapshot
        $sourcesStats = [];
        $sourceLinks = [];

        if (!empty($sourcesSnapshot)) {
            foreach ($sourcesSnapshot as $source) {
                if (is_array($source)) {
                    $sourceName = $source['source'] ?? '—';
                    $sourceRate = (float)($source['rate_per_hour'] ?? 0);
                    $link = $source['link'] ?? null;
                    
                    $sourcesStats[] = [
                        'name' => $sourceName,
                        'rate' => $sourceRate,
                        'url' => $link,
                        'date' => $source['source_date'] ?? $source['date'] ?? null,
                    ];
                    
                    if ($link) {
                        $sourceLinks[] = $link;
                    }
                }
            }
        }

        // Получить used_rates из justification_snapshot
        $usedRates = $justificationSnapshot['used_rates'] ?? [];
        if (empty($usedRates) && !empty($sourcesSnapshot)) {
            // Fallback: если нет used_rates - берём все источники как используемые
            $usedRates = array_column($sourcesSnapshot, 'rate_per_hour');
        }

        // Подготовить список работ для этого профиля
        $worksForDisplay = [];
        $totalHours = 0;
        $totalCost = 0;

        foreach ($works as $work) {
            if ($work instanceof LaborWorkDto) {
                // Пересчитываем стоимость по актуальной ставке (для preview)
                $workCost = $isPreview 
                    ? round($work->hours * $rate, 2) 
                    : ($work->cost ?? round($work->hours * $rate, 2));

                $worksForDisplay[] = [
                    'title' => $work->title,
                    'hours' => $work->hours,
                    'rate' => $isPreview ? $rate : $work->rate_per_hour,
                    'cost' => $workCost,
                ];
                $totalHours += $work->hours;
                $totalCost += $workCost;
            }
        }

        return [
            'profile_name' => $profileName,
            'rate' => $rate,
            'region' => $regionName,
            'date' => $date,
            'calculation_method' => $this->mapCalculationMethodToRussian($calculationMethod),
            'is_preview' => $isPreview,
            
            // Модель формирования ставки
            'rate_model' => $rateModel,
            'base_rate' => $baseRate ?? $rate,
            'model_params' => $modelParams,
            'model_breakdown' => $modelBreakdown,
            
            // Источники
            'sources_count_used' => count($usedRates),
            'sources_stats' => $sourcesStats,
            'source_links' => $sourceLinks,
            
            // Работы
            'works' => $worksForDisplay,
            'total_hours' => $totalHours,
            'total_cost' => round($totalCost, 2),
            
            // Служебное
            'additional_note' => $justificationSnapshot['additional_note'] ?? null,
        ];
    }

    /**
     * Маппировать метод расчёта на русский язык
     */
    private function mapCalculationMethodToRussian(string $method): string
    {
        $mapping = [
            'median' => 'медиана',
            'average' => 'среднее значение',
            'mean' => 'среднее значение',
            'mode' => 'мода',
        ];
        
        $lowerMethod = strtolower(trim($method));
        return $mapping[$lowerMethod] ?? $lowerMethod;
    }

    /**
     * Собрать источники ценовых данных из project_price_list_versions.
     * Каждая price_list_version уже хранит sha256, source_type, source_url, file_path.
     * Also includes facade quote sources grouped by supplier.
     */
    private function buildPriceSources(Project $project): array
    {
        $links = $project->priceListVersions()
            ->with('priceList.supplier')
            ->get();

        if ($links->isEmpty()) {
            return [];
        }

        $sources = [];
        foreach ($links as $version) {
            $sourceTypeLabel = match ($version->source_type) {
                'file' => 'Файл прайс-листа',
                'url' => 'Онлайн-источник',
                'manual' => 'Ручной ввод',
                default => 'Прайс-лист',
            };

            $sources[] = [
                'price_list_name' => $version->priceList?->name ?? '—',
                'supplier_name' => $version->priceList?->supplier?->name ?? null,
                'version_number' => $version->version_number,
                'price_list_version_id' => $version->id,
                'source_type' => $version->source_type,
                'source_type_label' => $sourceTypeLabel,
                'source_url' => $version->source_url,
                'original_filename' => $version->original_filename,
                'sha256' => $version->sha256,
                'effective_date' => $version->effective_date?->format('d.m.Y'),
                'captured_at' => $version->captured_at?->format('d.m.Y H:i'),
                'role' => $version->pivot->role ?? null,
                'linked_at' => $version->pivot->linked_at ?? null,
            ];
        }

        return $sources;
    }
}
