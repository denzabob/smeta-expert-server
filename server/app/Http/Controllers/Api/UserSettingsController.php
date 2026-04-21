<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserSettings;
use App\Services\UserLaborSettingsResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserSettingsController extends Controller
{
    public function __construct(
        private readonly UserLaborSettingsResolver $laborSettingsResolver,
    ) {
    }

    /**
     * Получить настройки пользователя (создаст если нет)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function get(Request $request): JsonResponse
    {
        $user = $request->user();

        $settings = $this->laborSettingsResolver->getOrCreateSettings($user);

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
            'labor_employer_insurance_rate' => ['numeric', 'min:0', 'max:1'],
            'labor_load_factor_calendar_hours' => ['integer', 'min:1'],
            'labor_load_factor_productive_hours' => ['integer', 'min:1'],
            'labor_planned_profitability_rate' => ['numeric', 'min:0', 'max:1'],
            'labor_aggregation_strategy' => ['string', Rule::in(UserLaborSettingsResolver::ALLOWED_AGGREGATION_STRATEGIES)],
            'labor_salary_range_strategy' => ['string', Rule::in(UserLaborSettingsResolver::ALLOWED_SALARY_RANGE_STRATEGIES)],
            'labor_rate_rounding_scale' => ['integer', 'min:0', 'max:6'],
        ]);

        $settings = $this->laborSettingsResolver->getOrCreateSettings($user);

        // Обновить только переданные поля
        $settings->update($validated);

        return response()->json($settings);
    }
}
