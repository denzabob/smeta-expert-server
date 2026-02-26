<?php

namespace App\Dto;

/**
 * DTO для агрегированных данных плитных материалов
 * Содержит расчёты по материалам с учётом коэффициентов отходов и детали позиций
 */
class PlateAggregateDto
{
    public function __construct(
        public int $id,                      // ID материала
        public string $name,                 // Название материала
        public float $area_details,          // Площадь деталей (м²)
        public float $waste_coeff,           // Коэффициент отходов
        public float $area_with_waste,       // Площадь с отходами (м²)
        public float $sheet_area,            // Площадь листа (м²)
        public int $sheets_count,            // Количество листов (только для режима по листам)
        public float $price_per_sheet,       // Цена за лист
        public float $price_per_m2,          // Цена за м²
        public float $total_cost,            // Итоговая стоимость
        public ?string $updated_at = null,   // Дата обновления материала
        public ?string $source_url = null,   // Источник цены
        public array $position_details = [], // Детали позиций: [['detail_type' => '...', 'quantity' => 1, 'width' => 800, 'length' => 600, 'area' => 0.48], ...]
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'area_details' => $this->area_details,
            'waste_coeff' => $this->waste_coeff,
            'area_with_waste' => $this->area_with_waste,
            'sheet_area' => $this->sheet_area,
            'sheets_count' => $this->sheets_count,
            'price_per_sheet' => $this->price_per_sheet,
            'price_per_m2' => $this->price_per_m2,
            'total_cost' => $this->total_cost,
            'updated_at' => $this->updated_at,
            'source_url' => $this->source_url,
            'position_details' => $this->position_details,
        ];
    }
}
