<?php

namespace App\Domain\PriceIndices\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class StatisticalCalculationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
