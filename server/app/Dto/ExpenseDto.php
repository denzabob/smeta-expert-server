<?php

namespace App\Dto;

class ExpenseDto
{
    public function __construct(
        public int $id,
        public string $type,
        public float $cost,
        public ?string $description = null,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'cost' => round($this->cost, 2),
            'description' => $this->description,
        ];
    }
}
