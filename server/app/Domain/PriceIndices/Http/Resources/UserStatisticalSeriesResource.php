<?php

namespace App\Domain\PriceIndices\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class UserStatisticalSeriesResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $item = $this->classifierItem;
        $payload = [
            'public_id' => $this->public_id,
            'classifier_item' => [
                'public_id' => $item->public_id,
                'classifier_code' => $item->classifier_code,
                'item_code' => $item->item_code,
                'item_name' => $item->name,
                'provider_code_kind' => $item->metadata_json['provider_code_kind'] ?? 'numeric',
            ],
            'indicator' => ['code' => $this->indicator->code, 'name' => $this->indicator->name],
            'territory' => ['code' => $this->territory->code, 'name' => $this->territory->name],
            'frequency' => $this->frequency,
            'comparison_basis' => $this->comparison_basis,
            'unit' => $this->unit,
            'period' => [
                'from' => substr((string) $this->period_from, 0, 7),
                'to' => substr((string) $this->period_to, 0, 7),
                'observations_count' => (int) $this->observations_count,
            ],
        ];

        if ($this->relationLoaded('activeImportContext')) {
            $import = $this->getRelation('activeImportContext');
            $payload['active_import'] = [
                'public_id' => $import->public_id,
                'importer_code' => $import->importer_code,
                'importer_version' => $import->importer_version,
                'published_at' => $import->published_at?->toISOString(),
            ];
        }

        return $payload;
    }
}
