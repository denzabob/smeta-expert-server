<?php

namespace App\Domain\PriceIndices\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class StatisticalObservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'series' => $this->whenLoaded('series', fn () => [
                'public_id' => $this->series->public_id,
                'item_code' => $this->series->classifierItem->item_code,
                'item_name' => $this->series->classifierItem->name,
                'territory_code' => $this->series->territory->code,
                'territory_name' => $this->series->territory->name,
                'indicator_code' => $this->series->indicator->code,
                'indicator_name' => $this->series->indicator->name,
                'frequency' => $this->series->frequency,
                'comparison_basis' => $this->series->comparison_basis,
                'unit' => $this->series->unit,
            ]),
            'period_start' => $this->period_start?->format('Y-m-d'),
            'value' => $this->value,
            'missing_reason' => $this->missing_reason?->value,
            'provenance' => [
                'source_file_public_id' => $this->whenLoaded('sourceFile', fn () => $this->sourceFile->public_id),
                'sheet_name' => $this->sheet_name,
                'source_row' => $this->source_row,
                'source_column' => $this->source_column,
                'source_cell_address' => $this->source_cell_address,
                'source_value_raw' => $this->source_value_raw,
                'footnote_marker' => $this->footnote_marker,
            ],
        ];
    }
}
