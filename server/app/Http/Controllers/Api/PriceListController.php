<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PriceList;
use App\Models\PriceListVersion;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PriceListController extends Controller
{
    /**
     * List ALL price lists for current user across all suppliers.
     *
     * GET /api/price-lists?type=materials&is_active=true
     */
    public function listAll(Request $request): JsonResponse
    {
        $request->validate([
            'type' => 'nullable|in:operations,materials',
            'domain' => 'nullable|in:operations,materials,finished_products',
            'is_active' => 'nullable|boolean',
        ]);

        $user = $request->user();
        $type = $request->input('type');
        $domain = $request->input('domain');
        $isActive = $request->boolean('is_active', false);

        $query = PriceList::whereHas('supplier', function ($q) use ($user) {
                $q->where('user_id', $user->id);
            })
            ->withCount('versions as versions_count')
            ->with('activeVersion');

        if ($type) {
            $query->where('type', $type);
        }
        if ($domain) {
            $query->forDomain($domain);
        }

        if ($isActive) {
            $query->where('is_active', true);
        }

        $priceLists = $query->orderBy('name')->get();
        $priceLists->each(function (PriceList $priceList) {
            $priceList->setAttribute('domain', $priceList->resolveDomain());
        });

        return response()->json($priceLists);
    }

    /**
     * List price lists for supplier with aggregates.
     * 
     * GET /api/suppliers/{supplier}/price-lists
     * 
     * Возвращает:
     * - versions_count
     * - active_version (id, captured_at, effective_date, source_type)
     */
    public function index(Request $request, Supplier $supplier): JsonResponse
    {
        $this->authorizeSupplier($request, $supplier);
        $request->validate([
            'type' => 'nullable|in:operations,materials',
            'domain' => 'nullable|in:operations,materials,finished_products',
        ]);

        $type = $request->input('type'); // 'operations' or 'materials'
        $domain = $request->input('domain'); // operations | materials | finished_products

        $query = $supplier->priceLists()
            ->withCount('versions as versions_count')
            ->with('activeVersion');

        if ($type) {
            $query->where('type', $type);
        }
        if ($domain) {
            $query->forDomain($domain);
        }

        $priceLists = $query->orderBy('name')->get();
        $priceLists->each(function (PriceList $priceList) {
            $priceList->setAttribute('domain', $priceList->resolveDomain());
        });

        return response()->json($priceLists);
    }

    /**
     * Create new price list.
     */
    public function store(Request $request, Supplier $supplier): JsonResponse
    {
        $this->authorizeSupplier($request, $supplier);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:operations,materials',
            'description' => 'nullable|string|max:1000',
            'default_currency' => 'nullable|string|size:3',
            'metadata' => 'nullable|array',
            'metadata.domain' => 'nullable|in:materials,finished_products',
        ]);
        $validated['metadata'] = PriceList::normalizeMetadataForType(
            $validated['type'],
            $validated['metadata'] ?? null
        );

        // Check uniqueness
        $exists = $supplier->priceLists()
            ->where('name', $validated['name'])
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Прайс-лист с таким именем уже существует у этого поставщика',
            ], 422);
        }

        $priceList = $supplier->priceLists()->create($validated);
        $priceList->setAttribute('domain', $priceList->resolveDomain());

        return response()->json($priceList, 201);
    }

    /**
     * Show price list with versions.
     */
    public function show(Request $request, PriceList $priceList): JsonResponse
    {
        $supplier = $priceList->supplier;
        $this->authorizeSupplier($request, $supplier);

        $priceList->load(['versions' => function ($query) {
            $query->orderBy('version_number', 'desc')->limit(10);
        }]);
        $priceList->setAttribute('domain', $priceList->resolveDomain());

        return response()->json($priceList);
    }

    /**
     * Update price list.
     */
    public function update(Request $request, PriceList $priceList): JsonResponse
    {
        $supplier = $priceList->supplier;
        $this->authorizeSupplier($request, $supplier);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'default_currency' => 'nullable|string|size:3',
            'metadata' => 'nullable|array',
            'metadata.domain' => 'nullable|in:materials,finished_products',
            'is_active' => 'nullable|boolean',
        ]);
        $effectiveType = $priceList->type;
        if (array_key_exists('metadata', $validated)) {
            $validated['metadata'] = PriceList::normalizeMetadataForType(
                $effectiveType,
                $validated['metadata'] ?? null
            );
        }

        // Check uniqueness if name is changing
        if (isset($validated['name']) && $validated['name'] !== $priceList->name) {
            $exists = $supplier->priceLists()
                ->where('name', $validated['name'])
                ->where('id', '!=', $priceList->id)
                ->exists();

            if ($exists) {
                return response()->json([
                    'message' => 'Прайс-лист с таким именем уже существует',
                ], 422);
            }
        }

        $priceList->update($validated);
        $priceList->setAttribute('domain', $priceList->resolveDomain());

        return response()->json($priceList);
    }

    /**
     * Delete price list.
     */
    public function destroy(Request $request, PriceList $priceList): JsonResponse
    {
        $supplier = $priceList->supplier;
        $this->authorizeSupplier($request, $supplier);

        $priceList->delete();

        return response()->json(null, 204);
    }

    /**
     * Get price list versions.
     */
    public function versions(Request $request, Supplier $supplier, PriceList $priceList): JsonResponse
    {
        $this->authorizeSupplier($request, $supplier);
        $this->authorizePriceList($priceList, $supplier);

        $versions = $priceList->versions()
            ->select(['id', 'version_number', 'status', 'currency', 'effective_date', 'captured_at', 'created_at'])
            ->withCount(['operationPrices', 'materialPrices'])
            ->orderBy('version_number', 'desc')
            ->paginate(20);

        return response()->json($versions);
    }

    /**
     * Show specific version with prices.
     */
    public function showVersion(Request $request, Supplier $supplier, PriceList $priceList, PriceListVersion $version): JsonResponse
    {
        $this->authorizeSupplier($request, $supplier);
        $this->authorizePriceList($priceList, $supplier);
        $this->authorizeVersion($version, $priceList);

        if ($priceList->type === PriceList::TYPE_OPERATIONS) {
            $version->load(['operationPrices.operation' => function ($query) {
                $query->select('id', 'name', 'unit', 'category');
            }]);
        } else {
            $version->load(['materialPrices.material' => function ($query) {
                $query->select('id', 'name', 'unit', 'article', 'category');
            }]);
        }

        return response()->json($version);
    }

    /**
     * Activate a version.
     */
    public function activateVersion(Request $request, Supplier $supplier, PriceList $priceList, PriceListVersion $version): JsonResponse
    {
        $this->authorizeSupplier($request, $supplier);
        $this->authorizePriceList($priceList, $supplier);
        $this->authorizeVersion($version, $priceList);

        if (!$version->canActivate()) {
            return response()->json([
                'message' => 'Эту версию нельзя активировать',
            ], 422);
        }

        $version->activate();

        return response()->json([
            'message' => 'Версия активирована',
            'version' => $version,
        ]);
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

    /**
     * Authorize that price list belongs to supplier.
     */
    private function authorizePriceList(PriceList $priceList, Supplier $supplier): void
    {
        if ($priceList->supplier_id !== $supplier->id) {
            abort(404, 'Прайс-лист не найден');
        }
    }

    /**
     * Authorize that version belongs to price list.
     */
    private function authorizeVersion(PriceListVersion $version, PriceList $priceList): void
    {
        if ($version->price_list_id !== $priceList->id) {
            abort(404, 'Версия не найдена');
        }
    }

    /**
     * Get actual (active or latest inactive) version for price list.
     * 
     * GET /api/price-lists/{priceList}/actual-version
     * 
     * Используется в сметах для выбора цен.
     */
    public function actualVersion(Request $request, PriceList $priceList): JsonResponse
    {
        // Проверка доступа
        if ($priceList->supplier->user_id !== $request->user()->id) {
            abort(403, 'Доступ запрещен');
        }

        // Сначала ищем active версию
        $active = $priceList->activeVersion;

        if ($active) {
            return response()->json([
                'version' => $active,
                'is_active' => true,
            ]);
        }

        // Fallback: последняя inactive версия
        $latestInactive = $priceList->versions()
            ->inactive()
            ->orderByDesc('effective_date')
            ->orderByDesc('captured_at')
            ->orderByDesc('version_number')
            ->first();

        if ($latestInactive) {
            return response()->json([
                'version' => $latestInactive,
                'is_active' => false,
                'warning' => 'Нет активной версии, используется последняя неактивная'
            ]);
        }

        return response()->json([
            'version' => null,
            'error' => 'Нет доступных версий'
        ], 404);
    }
}
