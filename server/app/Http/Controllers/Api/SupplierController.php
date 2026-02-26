<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    /**
     * List all suppliers for current user with aggregates.
     * 
     * Агрегаты:
     * - price_lists_count
     * - active_versions_count (сколько price_lists имеют активную версию)
     * - last_version_at (MAX captured_at по всем версиям всех прайс-листов)
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'price_list_type' => 'nullable|in:operations,materials',
            'price_list_domain' => 'nullable|in:operations,materials,finished_products',
        ]);

        $query = Supplier::forUser($request->user()->id)
            ->withCount('priceLists as price_lists_count');

        // Filter by price list type (operations / materials)
        if ($plType = $request->query('price_list_type')) {
            $query->whereHas('priceLists', function ($q) use ($plType) {
                $q->where('type', $plType);
            });
        }
        // Filter by business domain (operations / materials / finished_products)
        if ($plDomain = $request->query('price_list_domain')) {
            $query->whereHas('priceLists', function ($q) use ($plDomain) {
                $q->forDomain($plDomain);
            });
        }

        $suppliers = $query
            ->with(['priceLists.versions' => function ($query) {
                $query->select('id', 'price_list_id', 'status', 'captured_at')
                    ->latest('captured_at');
            }])
            ->orderBy('name')
            ->get();

        // Добавляем вычисляемые поля для каждого поставщика
        $suppliers->each(function ($supplier) {
            // Количество прайс-листов с активной версией
            $supplier->active_versions_count = $supplier->priceLists->filter(function ($priceList) {
                return $priceList->versions->where('status', \App\Models\PriceListVersion::STATUS_ACTIVE)->isNotEmpty();
            })->count();

            // Дата последней версии среди всех прайс-листов
            $allVersions = $supplier->priceLists->pluck('versions')->flatten();
            $supplier->last_version_at = $allVersions->pluck('captured_at')->filter()->max();

            // Убираем лишние данные из ответа
            $supplier->makeHidden('priceLists');
        });

        return response()->json($suppliers);
    }

    /**
     * Create new supplier.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50',
            'description' => 'nullable|string|max:1000',
            'website' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'contact_person' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000',
            'metadata' => 'nullable|array',
            'is_active' => 'nullable|boolean',
        ]);

        $validated['user_id'] = $request->user()->id;

        // Check uniqueness
        $exists = Supplier::where('user_id', $validated['user_id'])
            ->where('name', $validated['name'])
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Поставщик с таким именем уже существует',
            ], 422);
        }

        $supplier = Supplier::create($validated);

        return response()->json($supplier, 201);
    }

    /**
     * Show supplier details.
     */
    public function show(Request $request, Supplier $supplier): JsonResponse
    {
        $this->authorizeSupplier($request, $supplier);

        $supplier->load([
            'priceLists.versions' => function ($query) {
                $query->select('id', 'price_list_id', 'status', 'captured_at')
                    ->latest('captured_at');
            },
            'aliases' => function ($query) {
                $query->latest()->limit(100);
            },
        ]);

        $supplier->loadCount('priceLists as price_lists_count');

        // Количество прайс-листов с активной версией
        $supplier->active_versions_count = $supplier->priceLists->filter(function ($priceList) {
            return $priceList->versions->where('status', \App\Models\PriceListVersion::STATUS_ACTIVE)->isNotEmpty();
        })->count();

        // Дата последней версии среди всех прайс-листов
        $allVersions = $supplier->priceLists->pluck('versions')->flatten();
        $supplier->last_version_at = $allVersions->pluck('captured_at')->filter()->max();

        return response()->json($supplier);
    }

    /**
     * Update supplier.
     */
    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        $this->authorizeSupplier($request, $supplier);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'code' => 'nullable|string|max:50',
            'description' => 'nullable|string|max:1000',
            'website' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'contact_person' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000',
            'metadata' => 'nullable|array',
            'is_active' => 'nullable|boolean',
        ]);

        // Check uniqueness if name is changing
        if (isset($validated['name']) && $validated['name'] !== $supplier->name) {
            $exists = Supplier::where('user_id', $supplier->user_id)
                ->where('name', $validated['name'])
                ->where('id', '!=', $supplier->id)
                ->exists();

            if ($exists) {
                return response()->json([
                    'message' => 'Поставщик с таким именем уже существует',
                ], 422);
            }
        }

        $supplier->update($validated);

        return response()->json($supplier);
    }

    /**
     * Delete supplier with cascade (deletes all price lists, versions, and prices).
     */
    public function destroy(Request $request, Supplier $supplier): JsonResponse
    {
        $this->authorizeSupplier($request, $supplier);

        // Cascade deletion is handled by database constraints:
        // supplier -> price_lists -> price_list_versions -> operation_prices/material_prices
        $supplier->delete();

        return response()->json(null, 204);
    }

    /**
     * Get supplier aliases.
     */
    public function aliases(Request $request, Supplier $supplier): JsonResponse
    {
        $this->authorizeSupplier($request, $supplier);

        $type = $request->input('type'); // 'operation' or 'material'
        $search = $request->input('search');

        $query = $supplier->aliases();

        if ($type) {
            $query->where('internal_item_type', $type);
        }

        if ($search) {
            $query->where('external_name', 'like', "%{$search}%");
        }

        $aliases = $query->orderBy('last_seen_at', 'desc')
            ->paginate(50);

        return response()->json($aliases);
    }

    /**
     * Authorize that user owns this supplier.
     */
    private function authorizeSupplier(Request $request, Supplier $supplier): void
    {
        if ($supplier->user_id !== $request->user()->id) {
            abort(403, 'Доступ запрещен');
        }
    }
}
