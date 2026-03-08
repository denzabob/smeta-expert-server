<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaterialDimensionParseFailureResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fingerprint' => $this->fingerprint,
            'raw_text' => $this->raw_text,
            'normalized_text' => $this->normalized_text,
            'material_type' => $this->material_type,
            'source' => $this->source,
            'parse_error_reason' => $this->parse_error_reason,
            'occurrences' => (int) $this->occurrences,
            'first_seen_at' => optional($this->first_seen_at)->toIso8601String(),
            'last_seen_at' => optional($this->last_seen_at)->toIso8601String(),
            'resolved_length_mm' => $this->resolved_length_mm,
            'resolved_width_mm' => $this->resolved_width_mm,
            'resolved_thickness_mm' => $this->resolved_thickness_mm,
            'resolution_note' => $this->resolution_note,
            'resolved_by_user_id' => $this->resolved_by_user_id,
            'resolved_at' => optional($this->resolved_at)->toIso8601String(),
            'last_result' => $this->last_result,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
