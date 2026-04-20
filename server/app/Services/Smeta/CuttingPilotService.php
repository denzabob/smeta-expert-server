<?php

namespace App\Services\Smeta;

use App\Models\Material;
use App\Models\Operation;
use App\Models\ProjectPosition;
use App\Services\PriceImport\OperationPriceResolver;

class CuttingPilotService
{
    public function __construct(
        protected ?OperationPriceResolver $priceResolver = null
    ) {
        $this->priceResolver = $priceResolver ?? new OperationPriceResolver();
    }

    public function resolveOperation(Material $material): ?Operation
    {
        if ($material->type !== Material::TYPE_PLATE) {
            return null;
        }

        return Operation::query()
            ->where('exclusion_group', 'cutting')
            ->orderBy('id')
            ->first();
    }

    public function calculateQuantity(ProjectPosition $position): float
    {
        $areaM2 = (($position->width ?? 0) * ($position->length ?? 0)) / 1_000_000.0;

        return $areaM2 * ($position->quantity ?? 1);
    }

    public function resolvePrice(
        Operation $operation,
        ?string $priceMode = null,
        ?int $supplierId = null
    ): array {
        return $this->priceResolver->getPrice($operation->id, $priceMode, $supplierId);
    }
}
