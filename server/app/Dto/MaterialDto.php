<?php

namespace App\Dto;

class MaterialDto
{
    public function __construct(
        public int $id,
        public string $name,
        public string $article,
        public string $type,
        public float $unit_price,
        public string $unit,
        public float $quantity = 0,
        public float $total_cost = 0,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'article' => $this->article,
            'type' => $this->type,
            'unit_price' => round($this->unit_price, 2),
            'unit' => $this->unit,
            'quantity' => $this->quantity,
            'total_cost' => round($this->total_cost, 2),
        ];
    }
}
