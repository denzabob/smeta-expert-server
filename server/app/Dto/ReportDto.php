<?php

namespace App\Dto;

class ReportDto
{
    public function __construct(
        public ProjectMetaDto $project,
        public array $positions = [],           // PositionDto[]
        public array $plates = [],              // PlateAggregateDto[] - рассчитанные данные по плитам
        public array $edges = [],               // EdgeAggregateDto[] - рассчитанные данные по кромкам
        public array $facades = [],             // array[] - данные по фасадам
        public ?MaterialsDto $materials = null,
        public array $operations = [],          // OperationDto[]
        public array $fittings = [],            // FittingDto[]
        public array $expenses = [],            // ExpenseDto[]
        public array $labor_works = [],         // LaborWorkDto[] - монтажно-сборочные работы
        public ?TotalsDto $totals = null,
        public array $profile_rate_justifications = [],  // Обоснования расчётов ставок по профилям
        public array $price_sources = [],                  // Источники ценовых данных (price_list_versions)
    ) {
        // Инициализация пустых объектов по умолчанию
        if (!$this->materials) {
            $this->materials = new MaterialsDto();
        }
        if (!$this->totals) {
            $this->totals = new TotalsDto();
        }
    }

    public function toArray(): array
    {
        return [
            'project' => $this->project->toArray(),
            'positions' => array_map(fn(PositionDto $p) => $p->toArray(), $this->positions),
            'plates' => array_map(fn($p) => $p->toArray(), $this->plates),
            'edges' => array_map(fn($e) => $e->toArray(), $this->edges),
            'facades' => $this->facades,
            'materials' => $this->materials->toArray(),
            'operations' => array_map(fn($o) => $o instanceof OperationAggregateDto ? $o->toArray() : $o->toArray(), $this->operations),
            'fittings' => array_map(fn(FittingDto $f) => $f->toArray(), $this->fittings),
            'expenses' => array_map(fn(ExpenseDto $e) => $e->toArray(), $this->expenses),
            'labor_works' => array_map(fn($lw) => $lw instanceof \App\Dto\LaborWorkDto ? $lw->toArray() : $lw, $this->labor_works),
            'totals' => $this->totals->toArray(),
            'profile_rate_justifications' => $this->profile_rate_justifications,
            'price_sources' => $this->price_sources,
        ];
    }
}
