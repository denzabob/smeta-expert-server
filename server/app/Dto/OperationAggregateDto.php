<?php

namespace App\Dto;

/**
 * OperationAggregateDto - Рассчитанные данные операции
 * 
 * Содержит полную информацию об операции с расчётами
 */
class OperationAggregateDto
{
    public function __construct(
        public ?int $id = null,                    // ID операции (null если manual)
        public string $name = '',                  // Название операции (сверление, фрезеровка и т.д.)
        public string $category = '',              // Категория (drilling, routing, edge_processing, etc.)
        public string $unit = '',                  // Единица измерения (шт, м, м², часы и т.д.)
        public float $cost_per_unit = 0,          // Стоимость за единицу
        public float $quantity = 0,                // Количество единиц
        public float $total_cost = 0,              // Итоговая стоимость
        public bool $is_manual = false,            // true = ручной ввод, false = авто-расчёт
        public ?string $updated_at = null,         // Дата последнего обновления
        public ?string $source_url = null,         // Источник информации
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category,
            'unit' => $this->unit,
            'cost_per_unit' => $this->cost_per_unit,
            'quantity' => $this->quantity,
            'total_cost' => $this->total_cost,
            'is_manual' => $this->is_manual,
            'updated_at' => $this->updated_at,
            'source_url' => $this->source_url,
        ];
    }
}
