<?php

namespace App\Domain\PriceIndices\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class StatisticalSeriesAdminResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $classifierItem = $this->classifierItem;

        return [
            'public_id' => $this->public_id,
            'classifier_item' => [
                'public_id' => $classifierItem->public_id,
                'classifier_code' => $classifierItem->classifier_code,
                'item_code' => $classifierItem->item_code,
                'item_name' => $classifierItem->name,
                'provider_code_kind' => $classifierItem->metadata_json['provider_code_kind'] ?? 'numeric',
            ],
            'indicator' => [
                'code' => $this->indicator->code,
                'name' => $this->indicator->name,
            ],
            'territory' => [
                'code' => $this->territory->code,
                'name' => $this->territory->name,
            ],
            'frequency' => $this->frequency,
            'comparison_basis' => $this->comparison_basis,
            'unit' => $this->unit,
            'period' => [
                'from' => $this->period_from,
                'to' => $this->period_to,
                'observations_count' => (int) $this->observations_count,
            ],
        ];
    }
}
