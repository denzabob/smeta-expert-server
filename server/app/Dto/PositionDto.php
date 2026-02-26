<?php

namespace App\Dto;

class PositionDto
{
    public function __construct(
        public int $id,
        public int $project_id,
        public ?int $detail_type_id,
        public ?int $material_id,
        public ?int $edge_material_id,
        public ?string $edge_scheme,
        public float $quantity,
        public ?float $width,
        public ?float $length,
        public ?float $height,
        public ?string $detail_name = null,
        public ?array $material = null,
        public ?array $detail_type = null,
        public ?array $custom_operations = null,
        public string $kind = 'panel',
        public ?string $facade_material_name = null,
        public array $materials = [],
        public array $operations = [],
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'detail_type_id' => $this->detail_type_id,
            'material_id' => $this->material_id,
            'edge_material_id' => $this->edge_material_id,
            'edge_scheme' => $this->edge_scheme,
            'quantity' => $this->quantity,
            'width' => $this->width,
            'length' => $this->length,
            'height' => $this->height,
            'detail_name' => $this->detail_name,
            'material' => $this->material,
            'detail_type' => $this->detail_type,
            'custom_name' => $this->detail_name,
            'custom_operations' => $this->custom_operations,
            'kind' => $this->kind,
            'facade_material_name' => $this->facade_material_name,
            'materials' => array_map(fn(MaterialDto $m) => $m->toArray(), $this->materials),
            'operations' => array_map(fn(OperationDto $o) => $o->toArray(), $this->operations),
        ];
    }
}
