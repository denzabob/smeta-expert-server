<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateMaterialDimensionParseFailureRequest;
use App\Http\Resources\MaterialDimensionParseFailureResource;
use App\Models\MaterialDimensionParseFailure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminMaterialDimensionParseFailureController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MaterialDimensionParseFailure::class);

        $query = MaterialDimensionParseFailure::query()->orderByDesc('last_seen_at')->orderByDesc('id');

        if ($request->filled('material_type')) {
            $query->where('material_type', $request->string('material_type'));
        }
        if ($request->filled('source')) {
            $query->where('source', $request->string('source'));
        }
        if ($request->filled('status')) {
            $status = $request->string('status');
            if ($status === 'resolved') {
                $query->whereNotNull('resolved_at');
            }
            if ($status === 'unresolved') {
                $query->whereNull('resolved_at');
            }
        }
        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function ($q) use ($search) {
                $q->where('raw_text', 'like', "%{$search}%")
                    ->orWhere('normalized_text', 'like', "%{$search}%");
            });
        }

        $failures = $query->paginate($request->integer('per_page', 25));

        return response()->json([
            'data' => MaterialDimensionParseFailureResource::collection($failures->items()),
            'meta' => [
                'current_page' => $failures->currentPage(),
                'last_page' => $failures->lastPage(),
                'per_page' => $failures->perPage(),
                'total' => $failures->total(),
            ],
        ]);
    }

    public function show(MaterialDimensionParseFailure $materialDimensionParseFailure): JsonResponse
    {
        $this->authorize('view', $materialDimensionParseFailure);

        return response()->json(new MaterialDimensionParseFailureResource($materialDimensionParseFailure));
    }

    public function update(
        UpdateMaterialDimensionParseFailureRequest $request,
        MaterialDimensionParseFailure $materialDimensionParseFailure
    ): JsonResponse {
        $this->authorize('update', $materialDimensionParseFailure);

        $payload = $request->validated();

        $hasResolvedValues =
            array_key_exists('resolved_length_mm', $payload)
            || array_key_exists('resolved_width_mm', $payload)
            || array_key_exists('resolved_thickness_mm', $payload)
            || !empty($payload['resolution_note']);

        if ($hasResolvedValues) {
            $payload['resolved_at'] = now();
            $payload['resolved_by_user_id'] = $request->user()->id;
        }

        $materialDimensionParseFailure->update($payload);

        return response()->json(new MaterialDimensionParseFailureResource($materialDimensionParseFailure->fresh()));
    }
}
