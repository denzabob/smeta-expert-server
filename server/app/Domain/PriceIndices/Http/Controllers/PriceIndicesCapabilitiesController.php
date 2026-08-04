<?php

namespace App\Domain\PriceIndices\Http\Controllers;

use Illuminate\Http\JsonResponse;

final class PriceIndicesCapabilitiesController
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'application' => 'price_indices',
                'enabled' => (bool) config('price_indices.enabled'),
                'access' => true,
                'admin_only' => (bool) config('price_indices.admin_only'),
                'stage' => 'skeleton',
            ],
        ]);
    }
}
