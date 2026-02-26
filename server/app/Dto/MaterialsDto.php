<?php

namespace App\Dto;

class MaterialsDto
{
    /**
     * @var MaterialDto[]
     */
    public array $plates = [];

    /**
     * @var MaterialDto[]
     */
    public array $edges = [];

    public function toArray(): array
    {
        return [
            'plates' => array_map(fn(MaterialDto $m) => $m->toArray(), $this->plates),
            'edges' => array_map(fn(MaterialDto $m) => $m->toArray(), $this->edges),
        ];
    }
}
