<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OperationGroup;
use App\Models\OperationGroupLink;
use App\Models\SupplierOperation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OperationGroupController extends Controller
{
    /**
     * List all operation groups for current user.
     */
    public function index(Request $request): JsonResponse
    {
        $groups = OperationGroup::forUser($request->user()->id)
            ->withCount('supplierOperations')
            ->orderBy('name')
            ->get();

        return response()->json($groups);
    }

    /**
     * Create a new operation group.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'note' => 'nullable|string|max:1000',
            'expected_unit' => 'nullable|string|max:50',
        ]);

        $group = OperationGroup::create([
            'user_id' => $request->user()->id,
            'name' => $validated['name'],
            'note' => $validated['note'] ?? null,
            'expected_unit' => $validated['expected_unit'] ?? null,
        ]);

        return response()->json($group, 201);
    }

    /**
     * Show operation group with linked operations.
     */
    public function show(Request $request, OperationGroup $operationGroup): JsonResponse
    {
        $this->authorizeGroup($request, $operationGroup);

        $operationGroup->load([
            'supplierOperations' => function ($query) {
                $query->with('supplier:id,name')
                    ->select(['supplier_operations.*']);
            },
        ]);

        // Get latest prices for each linked operation
        $operationsWithPrices = $operationGroup->supplierOperations->map(function ($op) {
            $latestPrice = $op->latestPrice();
            return [
                'id' => $op->id,
                'name' => $op->name,
                'unit' => $op->unit,
                'category' => $op->category,
                'supplier_id' => $op->supplier_id,
                'supplier_name' => $op->supplier->name ?? 'Unknown',
                'latest_price' => $latestPrice ? [
                    'value' => $latestPrice->price_value,
                    'currency' => $latestPrice->currency,
                    'price_type' => $latestPrice->price_type,
                ] : null,
            ];
        });

        // Calculate median
        $median = $operationGroup->getMedianPrice();

        return response()->json([
            'group' => [
                'id' => $operationGroup->id,
                'name' => $operationGroup->name,
                'note' => $operationGroup->note,
                'expected_unit' => $operationGroup->expected_unit,
            ],
            'operations' => $operationsWithPrices,
            'median' => $median,
            'has_consistent_units' => $operationGroup->hasConsistentUnits(),
            'unique_units' => $operationGroup->getUniqueUnits()->values(),
        ]);
    }

    /**
     * Update operation group.
     */
    public function update(Request $request, OperationGroup $operationGroup): JsonResponse
    {
        $this->authorizeGroup($request, $operationGroup);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'note' => 'nullable|string|max:1000',
            'expected_unit' => 'nullable|string|max:50',
        ]);

        $operationGroup->update($validated);

        return response()->json($operationGroup);
    }

    /**
     * Delete operation group.
     */
    public function destroy(Request $request, OperationGroup $operationGroup): JsonResponse
    {
        $this->authorizeGroup($request, $operationGroup);

        $operationGroup->delete();

        return response()->noContent();
    }

    /**
     * Add supplier operations to group.
     */
    public function addOperations(Request $request, OperationGroup $operationGroup): JsonResponse
    {
        $this->authorizeGroup($request, $operationGroup);

        $validated = $request->validate([
            'supplier_operation_ids' => 'required|array|min:1',
            'supplier_operation_ids.*' => 'integer|exists:supplier_operations,id',
        ]);

        $added = 0;
        foreach ($validated['supplier_operation_ids'] as $opId) {
            // Check if already linked
            $exists = OperationGroupLink::where('operation_group_id', $operationGroup->id)
                ->where('supplier_operation_id', $opId)
                ->exists();

            if (!$exists) {
                OperationGroupLink::create([
                    'operation_group_id' => $operationGroup->id,
                    'supplier_operation_id' => $opId,
                ]);
                $added++;
            }
        }

        return response()->json([
            'message' => "Добавлено операций: {$added}",
            'added' => $added,
        ]);
    }

    /**
     * Remove supplier operations from group.
     */
    public function removeOperations(Request $request, OperationGroup $operationGroup): JsonResponse
    {
        $this->authorizeGroup($request, $operationGroup);

        $validated = $request->validate([
            'supplier_operation_ids' => 'required|array|min:1',
            'supplier_operation_ids.*' => 'integer',
        ]);

        $removed = OperationGroupLink::where('operation_group_id', $operationGroup->id)
            ->whereIn('supplier_operation_id', $validated['supplier_operation_ids'])
            ->delete();

        return response()->json([
            'message' => "Удалено операций: {$removed}",
            'removed' => $removed,
        ]);
    }

    /**
     * Get median price for group.
     */
    public function median(Request $request, OperationGroup $operationGroup): JsonResponse
    {
        $this->authorizeGroup($request, $operationGroup);

        $priceType = $request->input('price_type', 'retail');
        $currency = $request->input('currency', 'RUB');

        $result = $operationGroup->getMedianPrice($priceType, $currency);

        return response()->json($result);
    }

    /**
     * Authorize access to group.
     */
    private function authorizeGroup(Request $request, OperationGroup $group): void
    {
        if ($group->user_id !== $request->user()->id) {
            abort(403, 'Доступ к группе операций запрещен');
        }
    }
}
