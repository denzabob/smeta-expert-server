<?php

namespace App\Http\Controllers\Api\Parser;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Material;

class MaterialController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'article' => 'required|string|max:255',
            'type' => 'required|in:plate,edge,fitting',
            'unit' => 'required|in:м²,м.п.,шт',
            'price_per_unit' => 'required|numeric|min:0',
            'source_url' => 'required|url',
            'screenshot_path' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $data = $validator->validated();
        $data['user_id'] = auth()->id();
        $data['origin'] = 'user';

        // Ищем существующий материал от парсера
        $material = Material::where('article', $data['article'])
            ->where('origin', 'parser')
            ->first();

        if ($material && $material->price_per_unit != $data['price_per_unit']) {
            // Обновляем цену
            $material->price_per_unit = $data['price_per_unit'];
            $material->screenshot_path = $data['screenshot_path'] ?? $material->screenshot_path;
            $material->source_url = $data['source_url'] ?? $material->source_url;
            $material->version += 1;
            $material->save();

            // Запись в историю
            $material->priceHistories()->create([
                'version' => $material->version,
                'price_per_unit' => $data['price_per_unit'],
                'source_url' => $data['source_url'],
                'screenshot_path' => $data['screenshot_path'],
            ]);

        } elseif (!$material) {
            // Создаём новый
            $material = Material::create($data);

            // Первая запись в истории
            $material->priceHistories()->create([
                'version' => 1,
                'price_per_unit' => $data['price_per_unit'],
                'source_url' => $data['source_url'],
                'screenshot_path' => $data['screenshot_path'],
            ]);
        }

        return response()->json($material, 201);
    }
}
