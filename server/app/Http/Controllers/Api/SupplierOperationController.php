<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupplierOperation;
use App\Models\SupplierOperationPrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierOperationController extends Controller
{
    /**
     * List supplier operations with optional filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $query = SupplierOperation::query()
            ->with('supplier:id,name');

        // Filter by supplier
        if ($request->has('supplier_id')) {
            $query->where('supplier_id', $request->input('supplier_id'));
        }

        // Filter by user's suppliers only
        $query->whereHas('supplier', function ($q) use ($request) {
            $q->where('user_id', $request->user()->id);
        });

        // Search
        if ($request->has('search') && strlen($request->input('search')) >= 2) {
            $query->search($request->input('search'));
        }

        // Filter by category
        if ($request->has('category')) {
            $query->where('category', $request->input('category'));
        }

        // Filter by unit
        if ($request->has('unit')) {
            $query->where('unit', $request->input('unit'));
        }

        // Filter by not in group
        if ($request->has('not_in_group')) {
            $groupId = $request->input('not_in_group');
            $query->whereDoesntHave('operationGroups', function ($q) use ($groupId) {
                $q->where('operation_groups.id', $groupId);
            });
        }

        $operations = $query
            ->orderBy('name')
            ->paginate($request->input('per_page', 50));

        // Add latest price to each operation
        $operations->getCollection()->transform(function ($op) {
            $latestPrice = $op->latestPrice();
            $op->latest_price = $latestPrice ? [
                'value' => $latestPrice->price_value,
                'currency' => $latestPrice->currency,
                'unit' => $latestPrice->unit,
            ] : null;
            return $op;
        });

        return response()->json($operations);
    }

    /**
     * Show single supplier operation with price history.
     */
    public function show(Request $request, SupplierOperation $supplierOperation): JsonResponse
    {
        $this->authorizeOperation($request, $supplierOperation);

        $supplierOperation->load([
            'supplier:id,name',
            'operationGroups:id,name',
            'prices' => function ($query) {
                $query->with('priceListVersion:id,version,effective_date,status')
                    ->orderByDesc('created_at')
                    ->limit(10);
            },
        ]);

        return response()->json($supplierOperation);
    }

    /**
     * Get all unique categories from supplier operations.
     */
    public function categories(Request $request): JsonResponse
    {
        $categories = SupplierOperation::query()
            ->whereHas('supplier', function ($q) use ($request) {
                $q->where('user_id', $request->user()->id);
            })
            ->whereNotNull('category')
            ->distinct()
            ->pluck('category')
            ->sort()
            ->values();

        return response()->json($categories);
    }

    /**
     * Get all unique units from supplier operations.
     */
    public function units(Request $request): JsonResponse
    {
        $units = SupplierOperation::query()
            ->whereHas('supplier', function ($q) use ($request) {
                $q->where('user_id', $request->user()->id);
            })
            ->whereNotNull('unit')
            ->distinct()
            ->pluck('unit')
            ->sort()
            ->values();

        return response()->json($units);
    }

    /**
     * Search supplier operations for linking.
     */
    public function search(Request $request): JsonResponse
    {
        $query = $request->input('q', '');
        $limit = min($request->input('limit', 20), 100);

        if (strlen($query) < 2) {
            return response()->json([]);
        }

        $operations = SupplierOperation::query()
            ->whereHas('supplier', function ($q) use ($request) {
                $q->where('user_id', $request->user()->id);
            })
            ->search($query)
            ->with('supplier:id,name')
            ->limit($limit)
            ->get()
            ->map(function ($op) {
                $latestPrice = $op->latestPrice();
                return [
                    'id' => $op->id,
                    'name' => $op->name,
                    'unit' => $op->unit,
                    'category' => $op->category,
                    'supplier_id' => $op->supplier_id,
                    'supplier_name' => $op->supplier->name ?? 'Unknown',
                    'latest_price' => $latestPrice ? $latestPrice->price_value : null,
                ];
            });

        return response()->json($operations);
    }

    /**
     * Authorize access to supplier operation.
     */
    private function authorizeOperation(Request $request, SupplierOperation $operation): void
    {
        $supplier = $operation->supplier;
        if (!$supplier || $supplier->user_id !== $request->user()->id) {
            abort(403, 'Доступ к операции поставщика запрещен');
        }
    }
}
