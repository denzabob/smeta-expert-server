<?php
// app/Http/Controllers/Api/OperationController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Operation;
use Illuminate\Http\Request;

class OperationController extends Controller
{
    public function index()
    {
        return Operation::query()
            ->withCount([
                'prices as linked_prices_count' => function ($q) {
                    $q->whereNotNull('operation_id');
                }
            ])
            ->get();
    }

    public function getCategories()
    {
        $userId = auth()->id();

        // Получаем уникальные категории: свои + системные/парсинговые
        $categories = Operation::where('user_id', $userId)
            ->orWhere(function ($query) {
                $query->whereNull('user_id')
                    ->whereIn('origin', ['system', 'parser']);
            })
            ->distinct()
            ->pluck('category')
            ->values();

        return response()->json($categories);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'required|string|max:255',
            'exclusion_group' => 'nullable|string|max:50',
            'min_thickness' => 'nullable|numeric|min:0',
            'max_thickness' => 'nullable|numeric|min:0',
            'unit' => 'required|string|max:50',
            'description' => 'nullable|string',
        ]);

        $validated['user_id'] = auth()->id();
        $validated['origin'] = 'user';

        $operation = Operation::create($validated);
        return response()->json($operation, 201);
    }

    public function show(Operation $operation)
    {
        return $operation;
    }

    /**
     * Get operation price links (rows from supplier price lists linked to base operation).
     */
    public function priceLinks(Request $request, Operation $operation)
    {
        $userId = auth()->id();
        $limit = min(max((int) $request->input('limit', 100), 1), 500);

        $rows = \App\Models\OperationPrice::query()
            ->with([
                'priceListVersion:id,price_list_id,version_number,status',
                'priceListVersion.priceList:id,supplier_id,name',
                'priceListVersion.priceList.supplier:id,user_id,name',
            ])
            ->where('operation_id', $operation->id)
            ->whereHas('priceListVersion.priceList.supplier', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            })
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        $data = $rows->map(function ($row) {
            return [
                'id' => $row->id,
                'operation_id' => $row->operation_id,
                'source_name' => $row->source_name,
                'source_unit' => $row->source_unit,
                'source_price' => $row->source_price,
                'price_per_internal_unit' => $row->price_per_internal_unit,
                'currency' => $row->currency,
                'match_confidence' => $row->match_confidence,
                'supplier_id' => $row->supplier_id,
                'supplier_name' => $row->priceListVersion?->priceList?->supplier?->name,
                'price_list_version_id' => $row->price_list_version_id,
                'price_list_name' => $row->priceListVersion?->priceList?->name,
                'version_number' => $row->priceListVersion?->version_number,
                'version_status' => $row->priceListVersion?->status,
                'updated_at' => $row->updated_at,
            ];
        })->values();

        return response()->json([
            'operation_id' => $operation->id,
            'operation_name' => $operation->name,
            'count' => $data->count(),
            'data' => $data,
        ]);
    }

    public function update(Request $request, Operation $operation)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'category' => 'sometimes|required|string|max:255',
            'exclusion_group' => 'nullable|string|max:50',
            'min_thickness' => 'nullable|numeric|min:0',
            'max_thickness' => 'nullable|numeric|min:0',
            'unit' => 'sometimes|required|string|max:50',
            'description' => 'nullable|string',
        ]);

        $operation->update($validated);
        return $operation;
    }

    // app/Http/Controllers/Api/OperationController.php

    public function destroy(Operation $operation)
    {
        // Запрет удаления системных и парсинговых записей
        if (in_array($operation->origin, ['system', 'parser'])) {
            return response()->json([
                'message' => 'Системные операции нельзя удалять.'
            ], 403);
        }

        // Разрешено удалять только свои
        if ($operation->user_id !== auth()->id()) {
            return response()->json([
                'message' => 'Вы можете удалять только свои операции.'
            ], 403);
        }

        $operation->delete();
        return response()->json(null, 204);
    }

    /**
     * Search operations by name for price import resolution.
     */
    public function search(Request $request)
    {
        $query = $request->input('q', '');
        $limit = min($request->input('limit', 20), 200);

        if (strlen($query) < 2) {
            return response()->json([]);
        }

        $rawLowerQuery = mb_strtolower(trim($query), 'UTF-8');
        $normalizedQuery = Operation::normalizeSearchName($query);
        $searchQuery = '%' . $rawLowerQuery . '%';
        $normalizedSearchQuery = '%' . $normalizedQuery . '%';
        $tokens = array_values(array_filter(preg_split('/\s+/u', $normalizedQuery) ?: [], function ($token) {
            return mb_strlen($token, 'UTF-8') >= 2;
        }));

        $candidateLimit = min(max($limit * 10, 100), 500);

        $candidates = Operation::query()
            ->where(function ($q) use ($searchQuery, $normalizedSearchQuery, $tokens) {
                $q->whereRaw('LOWER(name) LIKE ?', [$searchQuery])
                    ->orWhereRaw('LOWER(search_name) LIKE ?', [$normalizedSearchQuery]);

                foreach ($tokens as $token) {
                    $tokenLike = '%' . $token . '%';
                    $q->orWhereRaw('LOWER(name) LIKE ?', [$tokenLike])
                        ->orWhereRaw('LOWER(search_name) LIKE ?', [$tokenLike]);
                }
            })
            ->select(['id', 'name', 'search_name', 'unit', 'category'])
            ->limit($candidateLimit)
            ->get();

        $operations = $candidates
            ->map(function ($operation) use ($rawLowerQuery, $normalizedQuery, $tokens) {
                $score = $this->calculateSearchScore(
                    (string) ($operation->name ?? ''),
                    (string) ($operation->search_name ?? ''),
                    $rawLowerQuery,
                    $normalizedQuery,
                    $tokens
                );

                return [
                    'id' => $operation->id,
                    'name' => $operation->name,
                    'unit' => $operation->unit,
                    'category' => $operation->category,
                    '_score' => $score,
                ];
            })
            ->sortByDesc('_score')
            ->values()
            ->take($limit)
            ->map(function ($row) {
                unset($row['_score']);
                return $row;
            })
            ->values();

        return response()->json($operations);
    }

    private function calculateSearchScore(string $name, string $searchName, string $rawLowerQuery, string $normalizedQuery, array $tokens): int
    {
        $nameLower = mb_strtolower($name, 'UTF-8');
        $searchLower = mb_strtolower($searchName, 'UTF-8');
        $score = 0;

        if ($nameLower === $rawLowerQuery) {
            $score += 1000;
        }
        if ($searchLower === $normalizedQuery) {
            $score += 900;
        }
        if ($rawLowerQuery !== '' && str_starts_with($nameLower, $rawLowerQuery)) {
            $score += 300;
        }
        if ($normalizedQuery !== '' && str_starts_with($searchLower, $normalizedQuery)) {
            $score += 280;
        }
        if ($rawLowerQuery !== '' && str_contains($nameLower, $rawLowerQuery)) {
            $score += 120;
        }
        if ($normalizedQuery !== '' && str_contains($searchLower, $normalizedQuery)) {
            $score += 100;
        }

        foreach ($tokens as $token) {
            if (str_contains($nameLower, $token)) {
                $score += 35;
            }
            if (str_contains($searchLower, $token)) {
                $score += 30;
            }
        }

        return $score;
    }

    /**
     * Get unique exclusion groups.
     */
    public function getExclusionGroups()
    {
        $userId = auth()->id();

        $groups = Operation::where('user_id', $userId)
            ->orWhere(function ($query) {
                $query->whereNull('user_id')
                    ->whereIn('origin', ['system', 'parser']);
            })
            ->whereNotNull('exclusion_group')
            ->distinct()
            ->pluck('exclusion_group')
            ->values();

        return response()->json($groups);
    }
}
