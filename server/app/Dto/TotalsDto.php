<?php

namespace App\Dto;

class TotalsDto
{
    public float $materials_cost = 0;
    public float $operations_cost = 0;
    public float $fittings_cost = 0;
    public float $expenses_cost = 0;
    public float $labor_works_cost = 0;  // Монтажно-сборочные работы
    public float $subtotal = 0;
    public float $total = 0;
    public float $grand_total = 0;

    public function toArray(): array
    {
        return [
            'materials_cost' => round($this->materials_cost, 2),
            'operations_cost' => round($this->operations_cost, 2),
            'fittings_cost' => round($this->fittings_cost, 2),
            'expenses_cost' => round($this->expenses_cost, 2),
            'labor_works_cost' => round($this->labor_works_cost, 2),
            'subtotal' => round($this->subtotal, 2),
            'total' => round($this->total, 2),
            'grand_total' => round($this->grand_total, 2),
        ];
    }
}
