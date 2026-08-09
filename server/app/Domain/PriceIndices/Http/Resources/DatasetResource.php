<?php

namespace App\Domain\PriceIndices\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DatasetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'provider_code' => $this->provider_code,
            'provider_name' => $this->provider_name,
            'data_kind' => $this->data_kind,
            'frequency' => $this->frequency,
            'classifier_code' => $this->classifier_code,
            'territory_scope' => $this->territory_scope,
            'is_enabled' => $this->is_enabled,
            'automatic_check_enabled' => $this->automatic_check_enabled,
            'check_schedule' => $this->check_schedule,
            'metadata_json' => $this->metadata_json,
            'sources_count' => $this->whenCounted('sources'),
            'source_files_count' => $this->whenCounted('sourceFiles'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
