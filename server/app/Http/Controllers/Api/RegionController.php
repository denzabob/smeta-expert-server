<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Region;
use Illuminate\Http\JsonResponse;

class RegionController extends Controller
{
    /**
     * Получить все регионы
     */
    public function index(): JsonResponse
    {
        try {
            $regions = Region::orderBy('region_name')->get()->map(fn($region) => [
                'id' => $region->id,
                'name' => $region->region_name ?? $region->name,
                'region_name' => $region->region_name ?? $region->name,
                'code' => $region->code,
                'is_active' => $region->is_active ?? true,
                'sort_order' => $region->sort_order,
            ]);

            return response()->json([
                'success' => true,
                'data' => $regions,
                'total' => count($regions),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения регионов',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
