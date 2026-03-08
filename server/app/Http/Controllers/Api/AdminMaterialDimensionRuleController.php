<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PreviewMaterialDimensionRuleRequest;
use App\Http\Requests\UpsertMaterialDimensionRuleRequest;
use App\Http\Resources\MaterialDimensionRuleResource;
use App\Models\MaterialDimensionRule;
use App\Services\MaterialDimensions\DimensionParseContext;
use App\Services\MaterialDimensions\DimensionTextNormalizer;
use App\Services\MaterialDimensions\Strategies\ManagedDbRuleStrategy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminMaterialDimensionRuleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MaterialDimensionRule::class);

        $query = MaterialDimensionRule::query()->orderBy('priority')->orderBy('id');

        if ($request->filled('material_type')) {
            $query->where('material_type', $request->string('material_type'));
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
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $rules = $query->paginate($request->integer('per_page', 25));

        return response()->json([
            'data' => MaterialDimensionRuleResource::collection($rules->items()),
            'meta' => [
                'current_page' => $rules->currentPage(),
                'last_page' => $rules->lastPage(),
                'per_page' => $rules->perPage(),
                'total' => $rules->total(),
            ],
        ]);
    }

    public function store(UpsertMaterialDimensionRuleRequest $request): JsonResponse
    {
        $this->authorize('create', MaterialDimensionRule::class);

        $payload = $request->validated();
        $payload['created_by_user_id'] = $request->user()->id;
        $payload['updated_by_user_id'] = $request->user()->id;

        $rule = MaterialDimensionRule::create($payload);

        return response()->json(new MaterialDimensionRuleResource($rule), 201);
    }

    public function show(MaterialDimensionRule $materialDimensionRule): JsonResponse
    {
        $this->authorize('view', $materialDimensionRule);

        return response()->json(new MaterialDimensionRuleResource($materialDimensionRule));
    }

    public function update(UpsertMaterialDimensionRuleRequest $request, MaterialDimensionRule $materialDimensionRule): JsonResponse
    {
        $this->authorize('update', $materialDimensionRule);

        $payload = $request->validated();
        $payload['updated_by_user_id'] = $request->user()->id;

        $materialDimensionRule->update($payload);

        return response()->json(new MaterialDimensionRuleResource($materialDimensionRule->fresh()));
    }

    public function destroy(MaterialDimensionRule $materialDimensionRule): \Illuminate\Http\Response
    {
        $this->authorize('delete', $materialDimensionRule);

        $materialDimensionRule->delete();

        return response()->noContent();
    }

    public function preview(
        PreviewMaterialDimensionRuleRequest $request,
        DimensionTextNormalizer $normalizer,
        ManagedDbRuleStrategy $managedDbRuleStrategy
    ): JsonResponse {
        $this->authorize('create', MaterialDimensionRule::class);

        $validated = $request->validated();
        $rawText = trim((string) $validated['test_text']);
        $normalizedText = $normalizer->normalize($rawText);

        $context = new DimensionParseContext(
            rawText: $rawText,
            normalizedText: $normalizedText,
            materialType: $validated['material_type'] ?? null,
            source: $validated['source'] ?? null,
            metadata: ['preview' => true]
        );

        $result = $managedDbRuleStrategy->previewRule($validated, $context);

        return response()->json([
            'parse_result' => $result->toArray(),
            'test_text' => $rawText,
        ]);
    }
}
