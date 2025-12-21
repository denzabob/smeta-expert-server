<?php

namespace App\Http\Controllers\Api\Parser;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Material;
use App\Models\MaterialPriceHistory;

class MaterialController extends Controller
{
    /**
     * Создаёт или обновляет материал, полученный от парсера.
     * Работает без авторизации (публичный endpoint для фоновых задач).
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'article' => 'required|string|max:255',
            'type' => 'required|in:plate,edge,fitting',
            'unit' => 'required|in:м²,м.п.,шт',
            'price_per_unit' => 'required|numeric|min:0',
            'source_url' => 'required|url',
            'screenshot_path' => 'nullable|string|max:512',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $data = $validator->validated();

        // Парсерные материалы — всегда общедоступные
        $data['user_id'] = null;
        $data['origin'] = 'parser';
        $data['is_active'] = true; // парсерные материалы всегда активны

        // Ищем **существующий парсерный** материал по артикулу
        $material = Material::where('article', $data['article'])
            ->where('origin', 'parser')
            ->first();

        if ($material) {
            // Если цена изменилась — обновляем
            if ($material->price_per_unit != $data['price_per_unit']) {
                $material->price_per_unit = $data['price_per_unit'];
                $material->source_url = $data['source_url'];
                $material->last_price_screenshot_path = $data['screenshot_path'];
                $material->version += 1;
                $material->save();

                // История изменений
                MaterialPriceHistory::create([
                    'material_id' => $material->id,
                    'version' => $material->version,
                    'price_per_unit' => $data['price_per_unit'],
                    'source_url' => $data['source_url'],
                    'screenshot_path' => $data['screenshot_path'],
                    'changed_at' => now(),
                ]);
            }
            // Если цена не изменилась — ничего не делаем
        } else {
            // Создаём новый парсерный материал
            $material = Material::create([
                'user_id' => null,
                'origin' => 'parser',
                'name' => $data['name'],
                'article' => $data['article'],
                'type' => $data['type'],
                'unit' => $data['unit'],
                'price_per_unit' => $data['price_per_unit'],
                'source_url' => $data['source_url'],
                'last_price_screenshot_path' => $data['screenshot_path'],
                'is_active' => true,
                'version' => 1,
            ]);

            // Первая запись в историю
            MaterialPriceHistory::create([
                'material_id' => $material->id,
                'version' => 1,
                'price_per_unit' => $data['price_per_unit'],
                'source_url' => $data['source_url'],
                'screenshot_path' => $data['screenshot_path'],
                'changed_at' => now(),
            ]);
        }

        return response()->json($material, 201);
    }
}
