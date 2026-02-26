<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GlobalNormohourSource;
use App\Models\Region;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Global Normohour Sources API Controller
 * 
 * Управление глобальными источниками нормо-часов
 * 
 * ЭТАП 1: Модель данных и правила хранения
 * - Single: salary_value заполнено, min/max = NULL
 * - Range: salary_value_min и salary_value_max заполнены, salary_value = salary_value_min
 * 
 * ЭТАП 4: Серверный пересчёт обязателен
 */
class GlobalNormohourSourceController extends Controller
{
    /**
     * Получить все источники нормо-часов
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = GlobalNormohourSource::with('positionProfile', 'region');

            // Фильтр по профилю должности
            if ($request->has('position_profile_id') && $request->position_profile_id) {
                $query->where('position_profile_id', $request->position_profile_id);
            }

            // Фильтр по регионам
            if ($request->has('region_id') && $request->region_id) {
                $query->where('region_id', $request->region_id);
            }

            // Фильтр по активности (только активные по умолчанию)
            if ($request->has('is_active')) {
                $query->where('is_active', $request->is_active === 'true' || $request->is_active === true);
            } else {
                $query->where('is_active', true);
            }

            // Поиск
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('source', 'ilike', "%$search%")
                        ->orWhere('note', 'ilike', "%$search%");
                });
            }

            // Сортировка по умолчанию: sort_order ASC, source_date DESC, id DESC
            $sources = $query
                ->orderBy('sort_order', 'asc')
                ->orderBy('source_date', 'desc')
                ->orderBy('id', 'desc')
                ->get();

            // Форматирование ответа
            $formatted = $sources->map(fn($source) => $this->formatForDisplay($source));

            return response()->json([
                'success' => true,
                'data' => $formatted,
                'total' => count($formatted),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения источников нормо-часов',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Создать новый источник
     * 
     * Валидация и нормализация согласно ЭТАП 4
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'position_profile_id' => 'nullable|integer|exists:position_profiles,id',
                'region_id' => 'required|integer|exists:regions,id',
                'source' => 'required|string|max:255',
                'type' => 'required|in:single,range',
                'salary_value' => 'nullable|numeric|min:0',
                'salary_value_min' => 'nullable|numeric|min:0',
                'salary_value_max' => 'nullable|numeric|min:0',
                'hours_per_month' => 'nullable|numeric|min:0.01',
                'source_date' => 'nullable|date',
                'link' => 'nullable|url|max:500',
                'note' => 'nullable|string|max:1000',
                'is_active' => 'nullable|boolean',
                'sort_order' => 'nullable|integer',
            ]);

            // Дополнительная валидация
            if ($validated['type'] === 'single') {
                if (empty($validated['salary_value'])) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка валидации',
                        'errors' => ['salary_value' => ['Зарплата обязательна для типа "Одно значение"']],
                    ], 422);
                }
            } elseif ($validated['type'] === 'range') {
                if (empty($validated['salary_value_min']) || empty($validated['salary_value_max'])) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка валидации',
                        'errors' => ['salary_range' => ['Оба значения диапазона обязательны']],
                    ], 422);
                }
                if ($validated['salary_value_min'] > $validated['salary_value_max']) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка валидации',
                        'errors' => ['salary_range' => ['Минимум не может быть больше максимума']],
                    ], 422);
                }
            }

            // Нормализация данных
            $normalized = $this->normalizeData($validated);

            $source = GlobalNormohourSource::create($normalized);

            return response()->json([
                'success' => true,
                'message' => 'Источник нормо-часов успешно создан',
                'data' => $this->formatForDisplay($source->fresh(['positionProfile', 'region'])),
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания источника нормо-часов',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить конкретный источник
     */
    public function show(int $id): JsonResponse
    {
        try {
            $source = GlobalNormohourSource::findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $source,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Источник нормо-часов не найден',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения источника',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Обновить источник
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $source = GlobalNormohourSource::findOrFail($id);

            $validated = $request->validate([
                'position_profile_id' => 'nullable|integer|exists:position_profiles,id',
                'region_id' => 'required|integer|exists:regions,id',
                'source' => 'required|string|max:255',
                'type' => 'required|in:single,range',
                'salary_value' => 'nullable|numeric|min:0',
                'salary_value_min' => 'nullable|numeric|min:0',
                'salary_value_max' => 'nullable|numeric|min:0',
                'hours_per_month' => 'nullable|numeric|min:0.01',
                'source_date' => 'nullable|date',
                'link' => 'nullable|url|max:500',
                'note' => 'nullable|string|max:1000',
                'is_active' => 'nullable|boolean',
                'sort_order' => 'nullable|integer',
            ]);

            // Дополнительная валидация
            if ($validated['type'] === 'single') {
                if (empty($validated['salary_value'])) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка валидации',
                        'errors' => ['salary_value' => ['Зарплата обязательна для типа "Одно значение"']],
                    ], 422);
                }
            } elseif ($validated['type'] === 'range') {
                if (empty($validated['salary_value_min']) || empty($validated['salary_value_max'])) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка валидации',
                        'errors' => ['salary_range' => ['Оба значения диапазона обязательны']],
                    ], 422);
                }
                if ($validated['salary_value_min'] > $validated['salary_value_max']) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка валидации',
                        'errors' => ['salary_range' => ['Минимум не может быть больше максимума']],
                    ], 422);
                }
            }

            // Нормализация данных
            $normalized = $this->normalizeData($validated);

            $source->update($normalized);

            return response()->json([
                'success' => true,
                'message' => 'Источник нормо-часов успешно обновлен',
                'data' => $this->formatForDisplay($source->fresh(['positionProfile', 'region'])),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Источник нормо-часов не найден',
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления источника',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Удалить источник
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $source = GlobalNormohourSource::findOrFail($id);
            $source->delete();

            return response()->json([
                'success' => true,
                'message' => 'Источник нормо-часов успешно удален',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Источник нормо-часов не найден',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления источника',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить источники для конкретного профиля и региона
     */
    public function getForProfile(int $positionProfileId, ?int $regionId = null): JsonResponse
    {
        try {
            $query = GlobalNormohourSource::where('position_profile_id', $positionProfileId)
                ->where('is_active', true);

            if ($regionId) {
                $query->where('region_id', $regionId);
            }

            $sources = $query->orderBy('source')->get();

            return response()->json([
                'success' => true,
                'data' => $sources,
                'count' => count($sources),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения источников',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Переключить активность источника
     */
    public function toggleActive(int $id): JsonResponse
    {
        try {
            $source = GlobalNormohourSource::findOrFail($id);
            $source->update(['is_active' => !$source->is_active]);

            return response()->json([
                'success' => true,
                'message' => $source->is_active ? 'Источник активирован' : 'Источник деактивирован',
                'data' => $this->formatForDisplay($source),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Источник нормо-часов не найден',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка переключения активности',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Нормализация данных согласно ЭТАП 1
     * 
     * Single:
     * - salary_value заполнено
     * - salary_value_min/max = NULL
     * - salary_month = salary_value
     * - rate_per_hour = salary_value / hours_per_month
     * 
     * Range:
     * - salary_value_min и salary_value_max заполнены
     * - salary_value = salary_value_min
     * - salary_month = salary_value_min
     * - min_rate = salary_value_min / hours_per_month
     * - max_rate = salary_value_max / hours_per_month
     * - rate_per_hour = min_rate
     */
    private function normalizeData(array $data): array
    {
        $hoursPerMonth = $data['hours_per_month'] ?? 160;
        $salaryPeriod = $data['salary_period'] ?? 'month';
        $sourceDate = $data['source_date'] ?? date('Y-m-d');
        $isActive = $data['is_active'] ?? true;
        $sortOrder = $data['sort_order'] ?? 0;

        $normalized = [
            'position_profile_id' => $data['position_profile_id'] ?? null,
            'region_id' => $data['region_id'],
            'source' => $data['source'],
            'salary_period' => $salaryPeriod,
            'hours_per_month' => $hoursPerMonth,
            'source_date' => $sourceDate,
            'link' => $data['link'] ?? null,
            'note' => $data['note'] ?? null,
            'is_active' => $isActive,
            'sort_order' => $sortOrder,
        ];

        if ($data['type'] === 'single') {
            // Правило 1: Single
            $normalized['salary_value'] = (float)$data['salary_value'];
            $normalized['salary_value_min'] = null;
            $normalized['salary_value_max'] = null;
            $normalized['salary_month'] = (float)$data['salary_value'];
            $normalized['rate_per_hour'] = round((float)$data['salary_value'] / $hoursPerMonth, 2);
            $normalized['min_rate'] = null;
            $normalized['max_rate'] = null;
        } else {
            // Правило 2: Range
            $normalized['salary_value'] = (float)$data['salary_value_min'];
            $normalized['salary_value_min'] = (float)$data['salary_value_min'];
            $normalized['salary_value_max'] = (float)$data['salary_value_max'];
            $normalized['salary_month'] = (float)$data['salary_value_min'];
            $normalized['min_rate'] = round((float)$data['salary_value_min'] / $hoursPerMonth, 2);
            $normalized['max_rate'] = round((float)$data['salary_value_max'] / $hoursPerMonth, 2);
            $normalized['rate_per_hour'] = $normalized['min_rate'];
        }

        return $normalized;
    }

    /**
     * Форматирование для отображения на фронтенде
     * 
     * Отображаемые поля:
     * - Зарплата: single → "100 000 ₽", range → "160 000–200 000 ₽"
     * - Ставка: single → "625 ₽/ч", range → "1000–1250 ₽/ч"
     */
    private function formatForDisplay(GlobalNormohourSource $source): array
    {
        $isRange = $source->salary_value_min !== null && $source->salary_value_max !== null;

        // Формирование строки зарплаты
        if ($isRange) {
            $salaryDisplay = number_format($source->salary_value_min, 0, ',', ' ') . '–' 
                           . number_format($source->salary_value_max, 0, ',', ' ') . ' ₽';
            $rateDisplay = number_format($source->min_rate ?? 0, 0, ',', ' ') . '–' 
                         . number_format($source->max_rate ?? 0, 0, ',', ' ') . ' ₽/ч';
        } else {
            $salaryDisplay = number_format($source->salary_value ?? 0, 0, ',', ' ') . ' ₽';
            $rateDisplay = number_format($source->rate_per_hour ?? 0, 0, ',', ' ') . ' ₽/ч';
        }

        return [
            'id' => $source->id,
            'position_profile_id' => $source->position_profile_id,
            'position_profile_name' => $source->positionProfile?->name ?? '—',
            'region_id' => $source->region_id,
            'region_name' => $source->region?->region_name ?? '—',
            'source' => $source->source,
            'salary_display' => $salaryDisplay,
            'salary_value' => $source->salary_value,
            'salary_value_min' => $source->salary_value_min,
            'salary_value_max' => $source->salary_value_max,
            'rate_display' => $rateDisplay,
            'rate_per_hour' => $source->rate_per_hour,
            'min_rate' => $source->min_rate,
            'max_rate' => $source->max_rate,
            'hours_per_month' => $source->hours_per_month,
            'source_date' => $source->source_date?->format('Y-m-d'),
            'source_date_formatted' => $source->source_date?->format('d.m.Y'),
            'link' => $source->link,
            'note' => $source->note,
            'is_active' => $source->is_active,
            'sort_order' => $source->sort_order,
            'is_range' => $isRange,
            'created_at' => $source->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $source->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
