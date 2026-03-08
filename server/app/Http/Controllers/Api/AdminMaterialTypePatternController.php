<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PreviewMaterialTypePatternRequest;
use App\Http\Requests\UpsertMaterialTypePatternRequest;
use App\Http\Resources\MaterialTypePatternResource;
use App\Models\MaterialTypePattern;
use App\Services\MaterialTypes\MaterialTypeDetectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminMaterialTypePatternController extends Controller
{
    public function __construct(private readonly MaterialTypeDetectionService $materialTypeDetection)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MaterialTypePattern::class);

        $query = MaterialTypePattern::query()->orderBy('priority')->orderBy('id');

        if ($request->filled('material_type')) {
            $query->where('material_type', $request->string('material_type'));
        }
        if ($request->filled('target_field')) {
            $query->where('target_field', $request->string('target_field'));
        }
        if ($request->filled('source')) {
            $query->where('source', $request->string('source'));
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOL));
        }
        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('pattern', 'like', "%{$search}%");
            });
        }

        $patterns = $query->paginate($request->integer('per_page', 25));

        return response()->json([
            'data' => MaterialTypePatternResource::collection($patterns->items()),
            'meta' => [
                'current_page' => $patterns->currentPage(),
                'last_page' => $patterns->lastPage(),
                'per_page' => $patterns->perPage(),
                'total' => $patterns->total(),
            ],
        ]);
    }

    public function store(UpsertMaterialTypePatternRequest $request): JsonResponse
    {
        $this->authorize('create', MaterialTypePattern::class);

        $payload = $this->normalizePayload($request->validated());
        $conflicts = $this->collectConflicts($payload);
        $this->assertNoBlockingConflicts($conflicts);

        $payload['created_by_user_id'] = $request->user()->id;
        $payload['updated_by_user_id'] = $request->user()->id;

        $pattern = MaterialTypePattern::create($payload);

        return response()->json(new MaterialTypePatternResource($pattern), 201);
    }

    public function show(MaterialTypePattern $materialTypePattern): JsonResponse
    {
        $this->authorize('view', $materialTypePattern);

        return response()->json(new MaterialTypePatternResource($materialTypePattern));
    }

    public function update(UpsertMaterialTypePatternRequest $request, MaterialTypePattern $materialTypePattern): JsonResponse
    {
        $this->authorize('update', $materialTypePattern);

        $payload = $this->normalizePayload($request->validated());
        $conflicts = $this->collectConflicts($payload, $materialTypePattern->id);
        $this->assertNoBlockingConflicts($conflicts);

        $payload['updated_by_user_id'] = $request->user()->id;

        $materialTypePattern->update($payload);

        return response()->json(new MaterialTypePatternResource($materialTypePattern->fresh()));
    }

    public function destroy(MaterialTypePattern $materialTypePattern): \Illuminate\Http\Response
    {
        $this->authorize('delete', $materialTypePattern);

        $materialTypePattern->delete();

        return response()->noContent();
    }

    public function preview(PreviewMaterialTypePatternRequest $request): JsonResponse
    {
        $this->authorize('create', MaterialTypePattern::class);

        $validated = $this->normalizePayload($request->validated());
        $testTitle = trim((string) ($validated['test_title'] ?? ''));
        $testUrl = trim((string) ($validated['test_url'] ?? ''));

        $result = $this->materialTypeDetection->preview(
            candidatePattern: $validated,
            title: $testTitle,
            url: $testUrl !== '' ? $testUrl : null,
        );

        $conflicts = $this->collectConflicts($validated);

        return response()->json([
            'preview_result' => $result,
            'conflicts' => $conflicts,
            'test_title' => $testTitle,
            'test_url' => $testUrl !== '' ? $testUrl : null,
        ]);
    }

    private function normalizePayload(array $payload): array
    {
        if (array_key_exists('source', $payload)) {
            $source = trim((string) ($payload['source'] ?? ''));
            $payload['source'] = $source === '' ? null : $source;
        }

        if (array_key_exists('flags', $payload)) {
            $flags = trim((string) ($payload['flags'] ?? ''));
            $payload['flags'] = $flags === '' ? 'iu' : $flags;
        }

        return $payload;
    }

    private function collectConflicts(array $payload, ?int $ignoreId = null): array
    {
        $exactDuplicate = MaterialTypePattern::query()
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where('rule_type', $payload['rule_type'])
            ->where('target_field', $payload['target_field'])
            ->where('pattern', $payload['pattern'])
            ->where('flags', $payload['flags'] ?? 'iu')
            ->where('material_type', $payload['material_type'])
            ->where(function ($q) use ($payload) {
                if (!empty($payload['source'])) {
                    $q->where('source', $payload['source']);
                } else {
                    $q->whereNull('source');
                }
            })
            ->first();

        $priorityConflicts = MaterialTypePattern::query()
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where('is_active', true)
            ->where('priority', $payload['priority'] ?? 100)
            ->where('target_field', $payload['target_field'])
            ->where('rule_type', $payload['rule_type'])
            ->where('material_type', '!=', $payload['material_type'])
            ->where(function ($q) use ($payload) {
                if (!empty($payload['source'])) {
                    $q->where('source', $payload['source']);
                } else {
                    $q->whereNull('source');
                }
            })
            ->orderBy('id')
            ->get(['id', 'name', 'material_type']);

        return [
            'has_exact_duplicate' => $exactDuplicate !== null,
            'exact_duplicate' => $exactDuplicate ? [
                'id' => $exactDuplicate->id,
                'name' => $exactDuplicate->name,
            ] : null,
            'priority_conflicts' => $priorityConflicts->map(fn (MaterialTypePattern $pattern) => [
                'id' => $pattern->id,
                'name' => $pattern->name,
                'material_type' => $pattern->material_type,
            ])->values(),
        ];
    }

    private function assertNoBlockingConflicts(array $conflicts): void
    {
        if (!empty($conflicts['has_exact_duplicate'])) {
            throw ValidationException::withMessages([
                'pattern' => 'Duplicate rule detected: the same pattern already exists for this scope and material type.',
            ]);
        }

        if (!empty($conflicts['priority_conflicts']) && count($conflicts['priority_conflicts']) > 0) {
            $ids = collect($conflicts['priority_conflicts'])->pluck('id')->implode(', ');
            throw ValidationException::withMessages([
                'priority' => 'Priority conflict with active rules of other material types (IDs: ' . $ids . ').',
            ]);
        }
    }
}
