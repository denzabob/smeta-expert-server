<?php

namespace App\Dto;

class OperationDto
{
    public function __construct(
        public int $id,
        public string $name,
        public string $category,
        public float $cost_per_unit,
        public string $unit,
        public float $quantity = 0,
        public float $total_cost = 0,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category,
            'cost_per_unit' => round($this->cost_per_unit, 2),
            'unit' => $this->unit,
            'quantity' => $this->quantity,
            'total_cost' => round($this->total_cost, 2),
        ];
    }
}
