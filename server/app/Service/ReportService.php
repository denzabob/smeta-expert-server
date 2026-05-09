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
use App\Models\LaborEvidenceSource;
use App\Models\PositionProfile;
use App\Models\Project;
use App\Models\ProjectPosition;
use App\Services\LaborCostCalculationService;
use App\Services\ProjectProfileRateResolver;
use App\Services\RateModelCalculator;
use App\Services\Reports\ReportSettingsResolver;
use App\Services\Smeta\SmetaCalculator;

class ReportService
{
    public function __construct(
        private SmetaCalculator $calculator,
        private ProjectProfileRateResolver $rateResolver,
        private ?ReportSettingsResolver $reportSettingsResolver = null,
        private ?RateModelCalculator $rateModelCalculator = null,
    ) {
        $this->rateModelCalculator = $rateModelCalculator ?? new RateModelCalculator();
        $this->reportSettingsResolver = $reportSettingsResolver ?? new ReportSettingsResolver();
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
        $positions = $project->positions()->with(['detailType', 'material', 'facadeMaterial', 'finishedProductSpecification'])->get();

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

        // 10. Зафиксировать пользовательские формулировки отчётов для PDF и revision snapshot.
        $reportSettings = $this->reportSettingsResolver->forProject($project);

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
            report_settings: $reportSettings,
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
            $facadeMaterialName = $position->finishedProductSpecification?->name
                ?? $position->facadeMaterial?->name
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
                $operation->is_valid ? 'valid' : 'invalid',
                (string) $operation->pricing_unit,
                (string) $operation->resolved_price_unit,
            ]);

            if (!isset($grouped[$key])) {
                $grouped[$key] = new OperationAggregateDto(
                    id: $operation->id,
                    name: $operation->name,
                    category: $operation->category,
                    unit: $operation->unit,
                    cost_per_unit: (float) $operation->cost_per_unit,
                    quantity: 0.0,
                    total_cost: $operation->is_valid ? 0.0 : null,
                    is_manual: $operation->is_manual,
                    updated_at: $operation->updated_at,
                    source_url: $operation->source_url,
                    is_valid: $operation->is_valid,
                    unit_mismatch: $operation->unit_mismatch,
                    pricing_unit: $operation->pricing_unit,
                    resolved_price_unit: $operation->resolved_price_unit,
                );
            }

            $grouped[$key]->quantity += (float) $operation->quantity;
            if ($operation->is_valid) {
                $grouped[$key]->total_cost = ($grouped[$key]->total_cost ?? 0.0) + (float) ($operation->total_cost ?? 0.0);
            } else {
                $grouped[$key]->total_cost = null;
                $grouped[$key]->is_valid = false;
                $grouped[$key]->unit_mismatch = $grouped[$key]->unit_mismatch || $operation->unit_mismatch;
            }
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
            ->with([
                'steps',
                'laborProfile' => fn ($query) => $query->withTrashed()->select(['id', 'title']),
            ])
            ->orderBy('sort_order')
            ->get();

        return $laborWorks->map(function($work) {
            $ratePerHour = $work->rate_per_hour !== null ? (float) $work->rate_per_hour : null;
            $cost = $work->cost_total !== null
                ? (float) $work->cost_total
                : ($ratePerHour !== null ? round($work->hours * $ratePerHour, 2) : null);

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
                labor_profile_id: $work->labor_profile_id,
                labor_profile_name: $work->laborProfile?->title,
                project_profile_rate_id: $work->project_profile_rate_id,
                rate_per_hour: $ratePerHour,
                cost: $cost,
                steps: $steps,
            );
        })->all();
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
            if (!$operation->is_valid) {
                $totals->total_is_valid = false;
                continue;
            }

            $totals->operations_cost += $operation->total_cost ?? 0.0;
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
        if ($totals->total_is_valid) {
            $totals->total = $totals->subtotal;
            $totals->grand_total = $totals->subtotal;
            $totals->total_amount = $totals->subtotal;
        } else {
            $totals->total = null;
            $totals->grand_total = null;
            $totals->total_amount = null;
        }

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
        $project->loadMissing('region', 'user');

        if (!$project->user) {
            return [];
        }

        /** @var LaborCostCalculationService $calculationService */
        $calculationService = app(LaborCostCalculationService::class);
        $calculation = $calculationService->calculate($project, $project->user);

        $profilesById = [];
        foreach (($calculation['profiles'] ?? []) as $profilePayload) {
            if (!isset($profilePayload['labor_profile_id'])) {
                continue;
            }

            $profilesById[(int) $profilePayload['labor_profile_id']] = $profilePayload;
        }

        $worksByProfile = [];
        foreach ($laborWorkDtos as $work) {
            if (!($work instanceof LaborWorkDto) || empty($work->labor_profile_id)) {
                continue;
            }

            $worksByProfile[(int) $work->labor_profile_id][] = $work;
        }

        $profileIds = array_values(array_unique(array_merge(
            array_keys($profilesById),
            array_keys($worksByProfile)
        )));
        sort($profileIds);
        $evidenceById = $this->loadProjectLaborEvidenceById($project);

        $justifications = [];
        foreach ($profileIds as $profileId) {
            $profilePayload = $profilesById[$profileId] ?? null;
            $works = $worksByProfile[$profileId] ?? [];
            $profileName = $profilePayload['labor_profile_name']
                ?? ($works[0]->labor_profile_name ?? null)
                ?? 'Неизвестный профиль';

            $justifications[] = $this->buildEvidenceBasedJustificationEntry(
                profileName: $profileName,
                regionName: $calculation['region']['name'] ?? 'Не указан',
                calculatedAt: $calculation['calculated_at'] ?? null,
                profilePayload: $profilePayload,
                settingsSnapshot: $calculation['settings'] ?? [],
                works: $works,
                evidenceById: $evidenceById,
            );
        }

        return $justifications;
    }

    private function buildEvidenceBasedJustificationEntry(
        string $profileName,
        string $regionName,
        ?string $calculatedAt,
        ?array $profilePayload,
        array $settingsSnapshot,
        array $works,
        array $evidenceById,
    ): array {
        $usedSources = $profilePayload['sources']['used_sources'] ?? [];
        $usedCount = (int) ($profilePayload['sources']['used_count'] ?? count($usedSources));
        $aggregationMethod = (string) ($profilePayload['aggregation']['method'] ?? 'none');
        $aggregationMethodLabel = $this->mapCalculationMethodToRussian($aggregationMethod);
        $baseRate = isset($profilePayload['aggregation']['base_rate']) ? (float) $profilePayload['aggregation']['base_rate'] : null;
        $finalRate = isset($profilePayload['model']['final_rate']) ? (float) $profilePayload['model']['final_rate'] : null;
        $employerInsuranceRatePercent = round(((float) ($settingsSnapshot['employer_insurance_rate'] ?? 0)) * 100, 1);
        $loadFactorCalendarHours = isset($settingsSnapshot['load_factor_calendar_hours']) ? (int) $settingsSnapshot['load_factor_calendar_hours'] : null;
        $loadFactorProductiveHours = isset($settingsSnapshot['load_factor_productive_hours']) ? (int) $settingsSnapshot['load_factor_productive_hours'] : null;
        $loadFactorValue = isset($profilePayload['model']['load_factor']) ? (float) $profilePayload['model']['load_factor'] : null;
        $plannedProfitabilityRatePercent = round(((float) ($settingsSnapshot['planned_profitability_rate'] ?? 0)) * 100, 1);
        $roundingScale = isset($settingsSnapshot['rounding_scale']) ? (int) $settingsSnapshot['rounding_scale'] : null;

        $sourcesStats = [];
        $sourceLinks = [];
        $vacancyEvidence = [];
        foreach ($usedSources as $source) {
            $sourceId = isset($source['source_id']) ? (int) $source['source_id'] : null;
            /** @var LaborEvidenceSource|null $evidence */
            $evidence = ($sourceId && isset($evidenceById[$sourceId])) ? $evidenceById[$sourceId] : null;
            $evidenceRow = $this->buildVacancyEvidenceRow($source, $evidence);
            $name = trim((string) ($evidenceRow['provider_title'] ?? '')) ?: trim((string) ($source['provider'] ?? '')) ?: trim((string) ($source['title'] ?? ''));
            $url = $evidenceRow['source_url'] ?? null;
            $vacancyEvidence[] = $evidenceRow;

            $sourcesStats[] = [
                'name' => $name ?: '—',
                'rate' => isset($source['hourly_rate']) ? (float) $source['hourly_rate'] : null,
                'url' => $url,
                'date' => $evidenceRow['source_date'] ?? null,
                'provider_title' => $evidenceRow['provider_title'],
                'vacancy_title' => $evidenceRow['vacancy_title'],
                'employer_name' => $evidenceRow['employer_name'],
                'salary_display' => $evidenceRow['salary_display'],
                'derived_hourly_rate' => $evidenceRow['derived_hourly_rate'],
                'confidence' => $evidenceRow['confidence'],
                'captured_via' => $evidenceRow['captured_via'],
                'has_screenshot' => $evidenceRow['has_screenshot'],
            ];

            if ($url) {
                $sourceLinks[$url] = $url;
            }
        }

        $worksForDisplay = [];
        $totalHours = 0.0;
        $totalCost = 0.0;

        foreach ($works as $work) {
            if (!($work instanceof LaborWorkDto)) {
                continue;
            }

            $displayRate = $finalRate;
            $workCost = $displayRate !== null ? round((float) $work->hours * $displayRate, 2) : null;
            $worksForDisplay[] = [
                'title' => $work->title,
                'hours' => $work->hours,
                'rate' => $displayRate,
                'cost' => $workCost,
            ];
            $totalHours += (float) $work->hours;
            $totalCost += (float) ($workCost ?? 0);
        }

        $methodology = $this->buildMethodologyPayload(
            array_map(fn (array $row) => (float) ($row['rate'] ?? 0), array_filter($sourcesStats, fn (array $row) => $row['rate'] !== null)),
            $aggregationMethod,
            $baseRate
        );

        $insufficientData = $finalRate === null || $usedCount === 0;

        return [
            'profile_name' => $profileName,
            'rate' => $finalRate,
            'region' => $regionName,
            'date' => $calculatedAt ? \Carbon\Carbon::parse($calculatedAt)->format('d.m.Y') : null,
            'calculation_method' => $aggregationMethodLabel,
            'calculation_method_code' => $aggregationMethod,
            'aggregation_method_code' => $aggregationMethod,
            'aggregation_method_label' => $aggregationMethodLabel,
            'is_preview' => false,
            'rate_model' => 'contractor',
            'base_rate' => $baseRate,
            'model_params' => null,
            'model_breakdown' => $this->transformLaborModelBreakdown($profilePayload['model'] ?? [], $baseRate),
            'employer_insurance_rate_percent' => $employerInsuranceRatePercent,
            'load_factor_calendar_hours' => $loadFactorCalendarHours,
            'load_factor_productive_hours' => $loadFactorProductiveHours,
            'load_factor_value' => $loadFactorValue,
            'planned_profitability_rate_percent' => $plannedProfitabilityRatePercent,
            'rounding_scale' => $roundingScale,
            'sources_count_used' => $usedCount,
            'sources_stats' => $sourcesStats,
            'source_links' => array_values($sourceLinks),
            'vacancy_evidence' => $vacancyEvidence,
            'works' => $worksForDisplay,
            'total_hours' => round($totalHours, 2),
            'total_cost' => round($totalCost, 2),
            'additional_note' => $insufficientData ? 'Недостаточно данных для определения ставки' : null,
            'insufficient_data' => $insufficientData,
            'methodology_text' => $methodology['text'],
            'methodology_formula' => $methodology['formula'],
        ];
    }

    private function loadProjectLaborEvidenceById(Project $project): array
    {
        return $project->laborEvidenceSources()
            ->with(['provider', 'evidenceRecord.assets'])
            ->get()
            ->keyBy('id')
            ->all();
    }

    private function buildVacancyEvidenceRow(array $usedSource, ?LaborEvidenceSource $evidence): array
    {
        $providerTitle = trim((string) ($evidence?->provider?->title ?? ''))
            ?: trim((string) ($usedSource['provider'] ?? ''))
            ?: '—';

        $sourceUrl = trim((string) ($evidence?->source_url ?? ''))
            ?: trim((string) ($usedSource['source_url'] ?? ''))
            ?: null;

        $salaryValue = $evidence?->salary_value !== null ? (float) $evidence->salary_value : null;
        $salaryValueMin = $evidence?->salary_value_min !== null ? (float) $evidence->salary_value_min : null;
        $salaryValueMax = $evidence?->salary_value_max !== null ? (float) $evidence->salary_value_max : null;
        $selectedSalaryAmount = isset($usedSource['selected_salary_amount']) && is_numeric($usedSource['selected_salary_amount'])
            ? (float) $usedSource['selected_salary_amount']
            : null;
        $derivedHourlyRate = $evidence?->derived_hourly_rate !== null ? (float) $evidence->derived_hourly_rate : null;
        $confidence = $this->extractLaborEvidenceConfidence($evidence);

        return [
            'provider_title' => $providerTitle,
            'source_url' => $sourceUrl,
            'vacancy_title' => trim((string) ($evidence?->vacancy_title ?? '')) ?: trim((string) ($usedSource['title'] ?? '')) ?: null,
            'employer_name' => trim((string) ($evidence?->employer_name ?? '')) ?: trim((string) ($usedSource['employer_name'] ?? '')) ?: null,
            'salary_raw_text' => $evidence?->salary_raw_text,
            'salary_value' => $salaryValue,
            'salary_value_min' => $salaryValueMin,
            'salary_value_max' => $salaryValueMax,
            'salary_period' => $evidence?->salary_period,
            'salary_display' => $this->formatLaborSalaryEvidence(
                selectedSalaryAmount: $selectedSalaryAmount,
                salaryValue: $salaryValue,
                salaryValueMin: $salaryValueMin,
                salaryValueMax: $salaryValueMax,
                salaryPeriod: $evidence?->salary_period,
                salaryRawText: $evidence?->salary_raw_text,
            ),
            'derived_hourly_rate' => $derivedHourlyRate,
            'source_date' => $evidence?->source_date?->toDateString() ?? ($usedSource['source_date'] ?? null),
            'confidence' => $confidence,
            'captured_via' => $evidence?->captured_via ?? null,
            'has_screenshot' => $evidence?->evidenceRecord?->assets?->contains(fn ($asset) => $asset->asset_type === 'screenshot') ?? false,
        ];
    }

    private function extractLaborEvidenceConfidence(?LaborEvidenceSource $evidence): ?float
    {
        if (!$evidence?->evidenceRecord) {
            return null;
        }

        $metadataConfidence = $evidence->evidenceRecord->metadata_json['confidence'] ?? null;
        if (is_numeric($metadataConfidence)) {
            return round((float) $metadataConfidence, 2);
        }

        if ($evidence->evidenceRecord->confidence_score !== null) {
            return round(((float) $evidence->evidenceRecord->confidence_score) / 100, 2);
        }

        return null;
    }

    private function formatLaborSalaryEvidence(
        ?float $selectedSalaryAmount,
        ?float $salaryValue,
        ?float $salaryValueMin,
        ?float $salaryValueMax,
        ?string $salaryPeriod,
        ?string $salaryRawText,
    ): ?string {
        $periodLabel = $this->mapSalaryPeriodToLabel($salaryPeriod);

        if ($selectedSalaryAmount !== null) {
            return $this->formatMoney($selectedSalaryAmount) . ($periodLabel ? ' / ' . $periodLabel : '');
        }

        if ($salaryValue !== null) {
            return $this->formatMoney($salaryValue) . ($periodLabel ? ' / ' . $periodLabel : '');
        }

        if ($salaryValueMin !== null && $salaryValueMax !== null) {
            return $this->formatMoney($salaryValueMin) . '–' . $this->formatMoney($salaryValueMax) . ($periodLabel ? ' / ' . $periodLabel : '');
        }

        if ($salaryValueMin !== null) {
            return 'от ' . $this->formatMoney($salaryValueMin) . ($periodLabel ? ' / ' . $periodLabel : '');
        }

        if ($salaryValueMax !== null) {
            return 'до ' . $this->formatMoney($salaryValueMax) . ($periodLabel ? ' / ' . $periodLabel : '');
        }

        $salaryRawText = trim((string) ($salaryRawText ?? ''));

        return $salaryRawText !== '' ? $salaryRawText : null;
    }

    private function mapSalaryPeriodToLabel(?string $salaryPeriod): ?string
    {
        return match (trim((string) $salaryPeriod)) {
            'hour' => 'в час',
            'day' => 'в день',
            'month' => 'в месяц',
            'year' => 'в год',
            'project' => 'за проект',
            default => null,
        };
    }

    private function formatMoney(float $amount): string
    {
        return number_format($amount, 0, ',', ' ') . ' ₽';
    }

    private function transformLaborModelBreakdown(array $model, ?float $baseRate): ?array
    {
        $finalRate = $model['final_rate'] ?? null;
        if ($baseRate === null || $finalRate === null) {
            return null;
        }

        return [
            'base_rate' => (float) $baseRate,
            'employer_contrib_pct' => round(((float) ($model['insurance_rate'] ?? 0)) * 100, 1),
            'contrib_rate' => isset($model['insurance_amount']) ? (float) $model['insurance_amount'] : null,
            'loaded_labor_rate' => isset($model['loaded_rate']) ? (float) $model['loaded_rate'] : null,
            'base_hours_month' => $model['calendar_hours'] ?? null,
            'billable_hours_month' => $model['productive_hours'] ?? null,
            'utilization_k' => isset($model['load_factor']) ? (float) $model['load_factor'] : null,
            'cost_rate' => isset($model['cost_rate']) ? (float) $model['cost_rate'] : null,
            'profit_pct' => round(((float) ($model['profitability_rate'] ?? 0)) * 100, 1),
            'profit_amount' => isset($model['profit_amount']) ? (float) $model['profit_amount'] : null,
            'contractor_rate' => (float) $finalRate,
            'final_rate' => (float) $finalRate,
        ];
    }

    private function buildMethodologyPayload(array $rates, string $method, ?float $resultRate): array
    {
        sort($rates);

        if ($rates === []) {
            return [
                'text' => 'Недостаточно валидных рыночных источников для выбора базовой ставки по данному профилю работ.',
                'formula' => 'Недостаточно данных для расчёта.',
            ];
        }

        $ratesStr = implode(', ', array_map(fn ($rate) => number_format((float) $rate, 2, ',', ' '), $rates));
        $resultLabel = $resultRate !== null ? number_format($resultRate, 2, ',', ' ') . ' руб./ч' : '—';

        return match ($method) {
            'single' => [
                'text' => 'Использован единственный валидный рыночный источник по данному профилю работ в регионе расчёта.',
                'formula' => 'Использована ставка из единственного валидного источника: ' . $resultLabel,
            ],
            'mean' => [
                'text' => 'Использовано среднее арифметическое валидных рыночных источников по профилю работ.',
                'formula' => 'Ставки: ' . $ratesStr . '. Среднее арифметическое принято как базовая ставка: ' . $resultLabel,
            ],
            'median' => [
                'text' => 'Использованы независимые открытые источники, отражающие рыночный спрос и уровень оплаты труда специалистов указанного профиля в регионе на дату расчёта. Применение медианы исключает влияние крайних значений и отражает типичное значение ставки.',
                'formula' => 'Отсортированные ставки: ' . $ratesStr . '. Медиана принята как базовая ставка: ' . $resultLabel,
            ],
            'min' => [
                'text' => 'Использовано минимальное значение из валидных рыночных источников по профилю работ.',
                'formula' => 'Ставки: ' . $ratesStr . '. Принято минимальное значение: ' . $resultLabel,
            ],
            'max' => [
                'text' => 'Использовано максимальное значение из валидных рыночных источников по профилю работ.',
                'formula' => 'Ставки: ' . $ratesStr . '. Принято максимальное значение: ' . $resultLabel,
            ],
            default => [
                'text' => 'Использован применённый агрегирующий метод для выбора базовой ставки по профилю работ.',
                'formula' => 'Ставки: ' . $ratesStr . '. Базовая ставка определена методом ' . $this->mapCalculationMethodToRussian($method) . ': ' . $resultLabel,
            ],
        };
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
            'single' => 'одно значение',
            'min' => 'минимум',
            'max' => 'максимум',
            'none' => 'не определён',
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
