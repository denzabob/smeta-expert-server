<?php

namespace App\Dto;

class FittingDto
{
    public function __construct(
        public int $id,
        public string $name,
        public string $article,
        public string $unit,
        public float $quantity = 0,
        public float $unit_price = 0,
        public float $total_cost = 0,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'article' => $this->article,
            'unit' => $this->unit,
            'quantity' => $this->quantity,
            'unit_price' => round($this->unit_price, 2),
            'total_cost' => round($this->total_cost, 2),
        ];
    }
}
