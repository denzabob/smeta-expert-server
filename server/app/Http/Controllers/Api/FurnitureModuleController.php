<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class FurnitureModuleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'article' => 'nullable|string|max:255',
        ]);

        $module = auth()->user()->furnitureModules()->create($validated);

        return response()->json($module, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $module = FurnitureModule::with(['details.fittings', 'details.material'])->findOrFail($id);
        $this->authorize('view', $module);
        return response()->json($module);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $module = FurnitureModule::findOrFail($id);
        $this->authorize('update', $module);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'article' => 'nullable|string|max:255',
            'details' => 'array',
            'details.*.id' => 'nullable|exists:details,id',
            'details.*.name' => 'required|string|max:255',
            'details.*.width_mm' => 'required|integer|min:1',
            'details.*.height_mm' => 'required|integer|min:1',
            'details.*.quantity' => 'required|integer|min:1',
            'details.*.material_id' => 'required|exists:materials,id',
            'details.*.edge_type' => 'nullable|string',
            'details.*.edge_config' => 'nullable|json',
            'details.*.fittings' => 'array',
            'details.*.fittings.*.id' => 'nullable|exists:fittings,id',
            'details.*.fittings.*.name' => 'required|string|max:255',
            'details.*.fittings.*.article' => 'required|string|max:255',
            'details.*.fittings.*.type' => 'required|string|max:255',
            'details.*.fittings.*.quantity' => 'required|integer|min:1',
            'details.*.fittings.*.unit_price' => 'required|numeric|min:0',
            'details.*.fittings.*.source_url' => 'nullable|url',
        ]);

        $module->update($validated);

        if (isset($validated['details'])) {
            foreach ($validated['details'] as $detailData) {
                $detail = $module->details()->updateOrCreate(['id' => $detailData['id'] ?? null], $detailData);

                if (isset($detailData['fittings'])) {
                    foreach ($detailData['fittings'] as $fittingData) {
                        $detail->fittings()->updateOrCreate(['id' => $fittingData['id'] ?? null], $fittingData);
                    }
                }
            }
        }

        return response()->json($module->load(['details.fittings', 'details.material']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $module = FurnitureModule::findOrFail($id);
        $this->authorize('delete', $module);
        $module->delete();
        return response()->noContent();
    }

    public function getCost(string $id, CostCalculator $calculator)
    {
        $module = FurnitureModule::with(['details.fittings', 'details.material'])->findOrFail($id);
        $this->authorize('view', $module);
        $cost = $calculator->calculate($module);
        return response()->json($cost);
    }
}
