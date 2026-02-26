<?php

namespace App\Dto;

class LaborWorkDto
{
    public function __construct(
        public int $id,
        public int $project_id,
        public string $title,
        public ?string $basis = null,
        public float $hours = 0,
        public ?string $note = null,
        public int $sort_order = 0,
        public ?int $project_profile_rate_id = null,
        public ?float $rate_per_hour = null,  // Ставка норм-часа в ₽/ч
        public ?float $cost = null,  // Вычисляемое: hours * rate_per_hour
        public array $steps = [],  // Подоперации (детализация)
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'title' => $this->title,
            'basis' => $this->basis,
            'hours' => $this->hours,
            'note' => $this->note,
            'sort_order' => $this->sort_order,
            'project_profile_rate_id' => $this->project_profile_rate_id,
            'rate_per_hour' => $this->rate_per_hour,
            'cost' => $this->cost,
            'steps' => $this->steps,
        ];
    }
}
