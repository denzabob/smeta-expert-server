<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserSettingsController extends Controller
{
    /**
     * Получить настройки пользователя (создаст если нет)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function get(Request $request): JsonResponse
    {
        $user = $request->user();

        // Получить или создать настройки пользователя
        $settings = $user->settings()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'region_id' => null,
                'default_expert_name' => null,
                'default_number' => null,
                'waste_coefficient' => 1.0,
                'repair_coefficient' => 1.0,
                'waste_plate_coefficient' => null,
                'waste_edge_coefficient' => null,
                'waste_operations_coefficient' => null,
                'apply_waste_to_plate' => true,
                'apply_waste_to_edge' => true,
                'apply_waste_to_operations' => false,
                'use_area_calc_mode' => false,
                'default_plate_material_id' => null,
                'default_edge_material_id' => null,
                'text_blocks' => null,
                'waste_plate_description' => null,
                'waste_edge_description' => null,
                'waste_operations_description' => null,
                'show_waste_plate_description' => false,
                'show_waste_edge_description' => false,
                'show_waste_operations_description' => false,
            ]
        );

        return response()->json($settings);
    }

    /**
     * Обновить настройки пользователя
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        // Валидация
        $validated = $request->validate([
            'region_id' => ['nullable', Rule::exists('regions', 'id')],
            'default_expert_name' => ['nullable', 'string', 'max:255'],
            'default_number' => ['nullable', 'string', 'max:255'],
            'waste_coefficient' => ['numeric', 'min:0'],
            'repair_coefficient' => ['numeric', 'min:0'],
            'waste_plate_coefficient' => ['nullable', 'numeric', 'min:0'],
            'waste_edge_coefficient' => ['nullable', 'numeric', 'min:0'],
            'waste_operations_coefficient' => ['nullable', 'numeric', 'min:0'],
            'apply_waste_to_plate' => ['boolean'],
            'apply_waste_to_edge' => ['boolean'],
            'apply_waste_to_operations' => ['boolean'],
            'use_area_calc_mode' => ['boolean'],
            'default_plate_material_id' => ['nullable', Rule::exists('materials', 'id')],
            'default_edge_material_id' => ['nullable', Rule::exists('materials', 'id')],
            // Эти поля хранятся в JSON-колонках, но в API принимаем их как объекты/массивы.
            'text_blocks' => ['nullable', 'array'],
            'waste_plate_description' => ['nullable', 'array'],
            'waste_edge_description' => ['nullable', 'array'],
            'waste_operations_description' => ['nullable', 'array'],
            'show_waste_plate_description' => ['boolean'],
            'show_waste_edge_description' => ['boolean'],
            'show_waste_operations_description' => ['boolean'],
        ]);

        // Получить или создать настройки
        $settings = $user->settings()->firstOrCreate(['user_id' => $user->id]);

        // Обновить только переданные поля
        $settings->update($validated);

        return response()->json($settings);
    }
}
