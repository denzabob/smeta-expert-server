<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Material;
use App\Models\MaterialPriceHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MaterialController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = auth()->user();

        $materials = Material::where(function ($query) use ($user) {
            $query->whereNull('user_id') // общие материалы (парсерные или системные)
            ->where('origin', 'parser'); // только парсерные
        })
            ->orWhere('user_id', $user->id) // личные материалы пользователя
            ->orderBy('name')
            ->get();

        return response()->json($materials);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $this->validatePayload($request);

        // Если origin = 'parser', user_id = NULL
        if ($validated['origin'] === 'parser') {
            $validated['user_id'] = null;
        } else {
            // Для 'user' — привязываем к текущему пользователю
            $validated['user_id'] = auth()->id();
        }

        $material = Material::create($validated);

        // Создаем первую запись в истории
        MaterialPriceHistory::create([
            'material_id' => $material->id,
            'version' => 1,
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
        $material = Material::findOrFail($id);
        return response()->json($material);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $material = Material::findOrFail($id);
        $validated = $this->validatePayload($request);

        // Проверка на принадлежность (если не парсерный)
        if ($material->origin === 'user' && $material->user_id !== auth()->id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

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

        // Проверка на принадлежность
        if ($material->origin === 'user' && $material->user_id !== auth()->id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

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
}
