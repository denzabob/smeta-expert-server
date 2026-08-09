<?php

namespace App\Domain\PriceIndices\Http\Controllers;

use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Exceptions\DatasetCodeImmutable;
use App\Domain\PriceIndices\Http\PriceIndicesErrorResponder;
use App\Domain\PriceIndices\Http\Requests\DatasetIndexRequest;
use App\Domain\PriceIndices\Http\Requests\StoreDatasetRequest;
use App\Domain\PriceIndices\Http\Requests\UpdateDatasetRequest;
use App\Domain\PriceIndices\Http\Resources\DatasetResource;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DatasetAdminController extends Controller
{
    public function __construct(private readonly PriceIndicesErrorResponder $errors)
    {
    }

    public function index(DatasetIndexRequest $request): AnonymousResourceCollection
    {
        $validated = $request->validated();
        $query = StatisticalDataset::query()->withCount(['sources', 'sourceFiles']);

        foreach (['provider_code', 'data_kind', 'frequency'] as $filter) {
            if (isset($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }

        if (isset($validated['is_enabled'])) {
            $query->where('is_enabled', filter_var($validated['is_enabled'], FILTER_VALIDATE_BOOLEAN));
        }

        $query->orderBy($validated['sort'] ?? 'created_at', $validated['direction'] ?? 'desc');

        return DatasetResource::collection($query->paginate($validated['per_page'] ?? 25));
    }

    public function store(StoreDatasetRequest $request): JsonResponse
    {
        $dataset = StatisticalDataset::query()->create($request->validated());
        $dataset->loadCount(['sources', 'sourceFiles']);

        return (new DatasetResource($dataset))->response()->setStatusCode(201);
    }

    public function show(StatisticalDataset $dataset): DatasetResource
    {
        return new DatasetResource($dataset->loadCount(['sources', 'sourceFiles']));
    }

    public function update(
        UpdateDatasetRequest $request,
        StatisticalDataset $dataset
    ): DatasetResource|JsonResponse {
        $validated = $request->validated();

        if (isset($validated['code'])
            && $validated['code'] !== $dataset->code
            && $dataset->sourceFiles()->exists()
        ) {
            return $this->errors->respond(new DatasetCodeImmutable());
        }

        $dataset->update($validated);

        return new DatasetResource($dataset->refresh()->loadCount(['sources', 'sourceFiles']));
    }
}
