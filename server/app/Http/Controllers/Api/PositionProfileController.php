<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PositionProfile;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PositionProfileController extends Controller
{
    /**
     * Получить все профили должностей
     */
    public function index(): JsonResponse
    {
        try {
            $profiles = PositionProfile::orderBy('name')->get();

            return response()->json([
                'success' => true,
                'data' => $profiles,
                'total' => count($profiles),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения профилей должностей',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Создать новый профиль должности
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string|max:1000',
                'sort_order' => 'nullable|integer|min:0',
                'rate_model' => 'nullable|string|in:labor,contractor',
                'employer_contrib_pct' => 'nullable|numeric|min:0|max:100',
                'base_hours_month' => 'nullable|integer|min:1|max:300',
                'billable_hours_month' => 'nullable|integer|min:1|max:300',
                'profit_pct' => 'nullable|numeric|min:0|max:100',
                'rounding_mode' => 'nullable|string|in:none,int,10,100',
            ]);

            // Валидация: billable_hours_month <= base_hours_month
            $baseHours = $validated['base_hours_month'] ?? 160;
            $billableHours = $validated['billable_hours_month'] ?? 120;
            if ($billableHours > $baseHours) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => ['billable_hours_month' => ['Оплачиваемые часы не могут превышать рабочие часы']],
                ], 422);
            }

            $profile = PositionProfile::create([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'sort_order' => $validated['sort_order'] ?? 0,
                'rate_model' => $validated['rate_model'] ?? 'labor',
                'employer_contrib_pct' => $validated['employer_contrib_pct'] ?? 30.00,
                'base_hours_month' => $baseHours,
                'billable_hours_month' => $billableHours,
                'profit_pct' => $validated['profit_pct'] ?? 15.00,
                'rounding_mode' => $validated['rounding_mode'] ?? 'none',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Профиль должности успешно создан',
                'data' => $profile,
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
                'message' => 'Ошибка создания профиля должности',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить конкретный профиль должности
     */
    public function show(int $id): JsonResponse
    {
        try {
            $profile = PositionProfile::findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $profile,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Профиль должности не найден',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения профиля',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Обновить профиль должности
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $profile = PositionProfile::findOrFail($id);

            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string|max:1000',
                'sort_order' => 'nullable|integer|min:0',
                'rate_model' => 'nullable|string|in:labor,contractor',
                'employer_contrib_pct' => 'nullable|numeric|min:0|max:100',
                'base_hours_month' => 'nullable|integer|min:1|max:300',
                'billable_hours_month' => 'nullable|integer|min:1|max:300',
                'profit_pct' => 'nullable|numeric|min:0|max:100',
                'rounding_mode' => 'nullable|string|in:none,int,10,100',
            ]);

            // Валидация: billable_hours_month <= base_hours_month
            $baseHours = $validated['base_hours_month'] ?? $profile->base_hours_month ?? 160;
            $billableHours = $validated['billable_hours_month'] ?? $profile->billable_hours_month ?? 120;
            if ($billableHours > $baseHours) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => ['billable_hours_month' => ['Оплачиваемые часы не могут превышать рабочие часы']],
                ], 422);
            }

            $profile->update([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'sort_order' => $validated['sort_order'] ?? 0,
                'rate_model' => $validated['rate_model'] ?? $profile->rate_model ?? 'labor',
                'employer_contrib_pct' => $validated['employer_contrib_pct'] ?? $profile->employer_contrib_pct ?? 30.00,
                'base_hours_month' => $baseHours,
                'billable_hours_month' => $billableHours,
                'profit_pct' => $validated['profit_pct'] ?? $profile->profit_pct ?? 15.00,
                'rounding_mode' => $validated['rounding_mode'] ?? $profile->rounding_mode ?? 'none',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Профиль должности успешно обновлен',
                'data' => $profile,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Профиль должности не найден',
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
                'message' => 'Ошибка обновления профиля',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Удалить профиль должности
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $profile = PositionProfile::findOrFail($id);
            $profile->delete();

            return response()->json([
                'success' => true,
                'message' => 'Профиль должности успешно удален',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Профиль должности не найден',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления профиля',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
