<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFinishedProductSpecificationRequest;
use App\Http\Requests\UpdateFinishedProductSpecificationRequest;
use App\Http\Resources\FinishedProductSpecificationResource;
use App\Models\FinishedProductSpecification;
use App\Services\FinishedProductSpecificationAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinishedProductSpecificationController extends Controller
{
    public function __construct(
        private FinishedProductSpecificationAccessService $accessService,
    ) {}

    public function index(Request $request)
    {
        $request->validate([
            'product_type' => 'nullable|in:' . FinishedProductSpecification::TYPE_FACADE,
            'is_active' => 'nullable|boolean',
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        $userId = (int) $request->user()->id;
        $perPage = (int) ($request->input('per_page', 50));

        $query = FinishedProductSpecification::query()
            ->forUser($userId)
            ->with(['computedPrice', 'aggregationProfile'])
            ->withCount(['priceSources as source_count'])
            ->orderBy('name');

        if ($request->filled('product_type')) {
            $query->ofType($request->string('product_type')->toString());
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('search')) {
            $search = '%' . trim((string) $request->input('search')) . '%';
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', $search)
                    ->orWhere('article', 'like', $search)
                    ->orWhere('collection', 'like', $search)
                    ->orWhere('decor_label', 'like', $search);
            });
        }

        return FinishedProductSpecificationResource::collection($query->paginate($perPage));
    }

    public function store(StoreFinishedProductSpecificationRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $payload['user_id'] = $request->user()->id;
        $payload['is_active'] = $payload['is_active'] ?? true;

        $specification = FinishedProductSpecification::create($payload);
        $specification->load(['computedPrice', 'aggregationProfile'])
            ->loadCount(['priceSources as source_count']);

        return (new FinishedProductSpecificationResource($specification))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, int $id): FinishedProductSpecificationResource
    {
        $specification = $this->accessService->resolveOwnedFacadeSpecification((int) $request->user()->id, $id);
        $specification->load(['computedPrice', 'aggregationProfile'])
            ->loadCount(['priceSources as source_count']);

        return new FinishedProductSpecificationResource($specification);
    }

    public function update(UpdateFinishedProductSpecificationRequest $request, int $id): FinishedProductSpecificationResource
    {
        $specification = $this->accessService->resolveOwnedFacadeSpecification((int) $request->user()->id, $id);
        $specification->update($request->validated());
        $specification->refresh();
        $specification->load(['computedPrice', 'aggregationProfile'])
            ->loadCount(['priceSources as source_count']);

        return new FinishedProductSpecificationResource($specification);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $specification = $this->accessService->resolveOwnedFacadeSpecification((int) $request->user()->id, $id);
        $specification->delete();

        return response()->json(null, 204);
    }
}
