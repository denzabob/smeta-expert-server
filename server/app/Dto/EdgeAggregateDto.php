<?php

namespace App\Dto;

/**
 * DTO для агрегированных данных кромочных материалов
 * Содержит расчёты кромки с учётом коэффициентов отходов и детали позиций
 */
class EdgeAggregateDto
{
    public function __construct(
        public int $id,                      // ID материала
        public string $name,                 // Название материала
        public float $length_linear,         // Линейная длина кромки (метры)
        public float $waste_coeff,           // Коэффициент отходов
        public float $length_with_waste,     // Длина с отходами (метры)
        public float $price_per_unit,        // Цена за единицу (метр/рулон)
        public float $total_cost,            // Итоговая стоимость
        public ?string $updated_at = null,   // Дата обновления материала
        public ?string $source_url = null,   // Источник цены
        public array $position_details = [], // Детали позиций: [['detail_type' => '...', 'quantity' => 1, 'width' => 800, 'length' => 600, 'scheme' => 'U', 'perimeter' => 2.8, 'length_total' => 2.8], ...]
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'length_linear' => $this->length_linear,
            'waste_coeff' => $this->waste_coeff,
            'length_with_waste' => $this->length_with_waste,
            'price_per_unit' => $this->price_per_unit,
            'total_cost' => $this->total_cost,
            'updated_at' => $this->updated_at,
            'source_url' => $this->source_url,
            'position_details' => $this->position_details,
        ];
    }
}
