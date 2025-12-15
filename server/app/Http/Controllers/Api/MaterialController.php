<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Material;
use App\Models\MaterialPriceHistory;
use Illuminate\Http\Request;

class MaterialController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $materials = Material::orderBy('name')->get();
        return response()->json($materials);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $this->validatePayload($request);

        $material = Material::create($validated);

        MaterialPriceHistory::create([
            'material_id' => $material->id,
            'version' => $material->version ?? 1,
            'price_per_unit' => $material->price_per_unit,
            'source_url' => $material->source_url,
            'screenshot_path' => $material->last_price_screenshot_path,
            'changed_at' => now(),
        ]);

        return response()->json($material, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $material = $this->findOwnedOrShared($id);

        return response()->json($material);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $material = Material::findOrFail($id);
        $validated = $this->validatePayload($request);

        $originalPrice = $material->price_per_unit;

        $material->fill($validated);

        if ($material->price_per_unit != $originalPrice) {
            $material->version = ($material->version ?? 1) + 1;
        }

        $material->save();

        if ($material->price_per_unit != $originalPrice) {
            MaterialPriceHistory::create([
                'material_id' => $material->id,
                'version' => $material->version,
                'price_per_unit' => $material->price_per_unit,
                'source_url' => $material->source_url,
                'screenshot_path' => $material->last_price_screenshot_path,
                'changed_at' => now(),
            ]);
        }

        return response()->json($material);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $material = Material::findOrFail($id);
        $material->delete();

        return response()->noContent();
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'origin' => 'required|in:user,parser',
            'name' => 'required|string|max:255',
            'article' => 'required|string|max:255',
            'type' => 'required|in:plate,edge,fitting',
            'price_per_unit' => 'required|numeric|min:0',
            'unit' => 'required|in:м²,м.п.,шт',
            'source_url' => 'nullable|url',
            'is_active' => 'boolean',
            'last_price_screenshot_path' => 'nullable|string|max:2048',
        ]);
    }

    private function findOwnedOrShared(string $id): Material
    {
        return Material::findOrFail($id);
    }
}
