<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemMaterial;
use App\Models\SystemMaterialPriceHistory;
use Illuminate\Http\Request;

class SystemMaterialController extends Controller
{
    public function index()
    {
        $materials = SystemMaterial::orderBy('name')->get();
        return response()->json($materials);
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request);

        $material = SystemMaterial::create($validated);

        SystemMaterialPriceHistory::create([
            'system_material_id' => $material->id,
            'version' => $material->version ?? 1,
            'price_per_unit' => $material->price_per_unit,
            'source_url' => $material->source_url,
            'screenshot_path' => $material->screenshot_path,
            'changed_at' => now(),
        ]);

        return response()->json($material, 201);
    }

    public function show(string $id)
    {
        $material = SystemMaterial::findOrFail($id);
        return response()->json($material);
    }

    public function update(Request $request, string $id)
    {
        $material = SystemMaterial::findOrFail($id);
        $validated = $this->validatePayload($request);

        $originalPrice = $material->price_per_unit;

        $material->fill($validated);

        if ($material->price_per_unit != $originalPrice) {
            $material->version = ($material->version ?? 1) + 1;
        }

        $material->save();

        if ($material->price_per_unit != $originalPrice) {
            SystemMaterialPriceHistory::create([
                'system_material_id' => $material->id,
                'version' => $material->version,
                'price_per_unit' => $material->price_per_unit,
                'source_url' => $material->source_url,
                'screenshot_path' => $material->screenshot_path,
                'changed_at' => now(),
            ]);
        }

        return response()->json($material);
    }

    public function destroy(string $id)
    {
        $material = SystemMaterial::findOrFail($id);
        $material->delete();

        return response()->noContent();
    }

    public function history(string $id)
    {
        $material = SystemMaterial::findOrFail($id);

        $history = $material->priceHistories()
            ->orderByDesc('version')
            ->orderByDesc('changed_at')
            ->get();

        return response()->json($history);
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'article' => 'required|string|max:255',
            'type' => 'required|in:plate,edge,fitting',
            'unit' => 'required|in:м²,м.п.,шт',
            'price_per_unit' => 'required|numeric|min:0',
            'supplier' => 'nullable|string|max:255',
            'source_url' => 'nullable|url',
            'screenshot_path' => 'nullable|string|max:2048',
            'is_active' => 'boolean',
        ]);
    }
}


