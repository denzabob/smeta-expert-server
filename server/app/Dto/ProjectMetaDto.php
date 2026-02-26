<?php

namespace App\Dto;

class ProjectMetaDto
{
    public function __construct(
        public int $id,
        public string $number,
        public string $expert_name,
        public string $address,
        public float $waste_coefficient,
        public float $repair_coefficient,
        public ?float $waste_plate_coefficient = null,
        public ?float $waste_edge_coefficient = null,
        public ?float $waste_operations_coefficient = null,
        public bool $apply_waste_to_plate = false,
        public bool $apply_waste_to_edge = false,
        public bool $apply_waste_to_operations = false,
        public bool $use_area_calc_mode = false,
        public ?int $default_plate_material_id = null,
        public ?int $default_edge_material_id = null,
        public ?array $text_blocks = null,
        public ?array $waste_plate_description = null,
        public bool $show_waste_plate_description = false,
        public ?array $waste_edge_description = null,
        public bool $show_waste_edge_description = false,
        public ?array $waste_operations_description = null,
        public bool $show_waste_operations_description = false,
        public ?float $normohour_rate = null,
        public ?string $normohour_region = null,
        public ?string $normohour_date = null,
        public ?string $normohour_method = null,
        public ?string $normohour_justification = null,
        public ?array $normohour_sources = null,  // Источники нормо-часов
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'expert_name' => $this->expert_name,
            'address' => $this->address,
            'waste_coefficient' => $this->waste_coefficient,
            'repair_coefficient' => $this->repair_coefficient,
            'waste_plate_coefficient' => $this->waste_plate_coefficient,
            'waste_edge_coefficient' => $this->waste_edge_coefficient,
            'waste_operations_coefficient' => $this->waste_operations_coefficient,
            'apply_waste_to_plate' => $this->apply_waste_to_plate,
            'apply_waste_to_edge' => $this->apply_waste_to_edge,
            'apply_waste_to_operations' => $this->apply_waste_to_operations,
            'use_area_calc_mode' => $this->use_area_calc_mode,
            'default_plate_material_id' => $this->default_plate_material_id,
            'default_edge_material_id' => $this->default_edge_material_id,
            'text_blocks' => $this->text_blocks,
            'waste_plate_description' => $this->waste_plate_description,
            'show_waste_plate_description' => $this->show_waste_plate_description,
            'waste_edge_description' => $this->waste_edge_description,
            'show_waste_edge_description' => $this->show_waste_edge_description,
            'waste_operations_description' => $this->waste_operations_description,
            'show_waste_operations_description' => $this->show_waste_operations_description,
            'normohour_rate' => $this->normohour_rate,
            'normohour_region' => $this->normohour_region,
            'normohour_date' => $this->normohour_date,
            'normohour_method' => $this->normohour_method,
            'normohour_justification' => $this->normohour_justification,
            'normohour_sources' => $this->normohour_sources,
        ];
    }
}
