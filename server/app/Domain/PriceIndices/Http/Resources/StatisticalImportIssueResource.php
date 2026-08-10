<?php

namespace App\Domain\PriceIndices\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class StatisticalImportIssueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'severity' => $this->severity->value,
            'code' => $this->code,
            'message' => $this->message,
            'sheet_name' => $this->sheet_name,
            'source_row' => $this->source_row,
            'source_column' => $this->source_column,
            'classifier_item_code' => $this->classifier_item_code,
            'details' => $this->details_json,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
