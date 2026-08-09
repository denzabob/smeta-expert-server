<?php

namespace App\Domain\PriceIndices\Http\Controllers;

use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Enums\SourceFileErrorCode;
use App\Domain\PriceIndices\Domain\Exceptions\SourceFileIngestionException;
use App\Domain\PriceIndices\Domain\Sources\StatisticalSource;
use App\Domain\PriceIndices\Http\PriceIndicesErrorResponder;
use App\Domain\PriceIndices\Http\Requests\SourceIndexRequest;
use App\Domain\PriceIndices\Http\Requests\StoreSourceRequest;
use App\Domain\PriceIndices\Http\Requests\UpdateSourceRequest;
use App\Domain\PriceIndices\Http\Resources\SourceResource;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SourceAdminController extends Controller
{
    public function __construct(private readonly PriceIndicesErrorResponder $errors)
    {
    }

    public function index(SourceIndexRequest $request): AnonymousResourceCollection
    {
        $validated = $request->validated();
        $query = StatisticalSource::query()
            ->with('dataset')
            ->withCount(['sourceFiles', 'checks']);

        if (isset($validated['dataset'])) {
            $query->whereHas('dataset', fn ($builder) => $builder->where('public_id', $validated['dataset']));
        }

        foreach (['is_enabled', 'automatic_check_enabled'] as $filter) {
            if (isset($validated[$filter])) {
                $query->where($filter, filter_var($validated[$filter], FILTER_VALIDATE_BOOLEAN));
            }
        }

        $query->orderBy($validated['sort'] ?? 'created_at', $validated['direction'] ?? 'desc');

        return SourceResource::collection($query->paginate($validated['per_page'] ?? 25));
    }

    public function store(StoreSourceRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $dataset = StatisticalDataset::query()
            ->where('public_id', $validated['dataset_public_id'])
            ->firstOrFail();
        unset($validated['dataset_public_id']);

        $source = $dataset->sources()->create($validated);

        return (new SourceResource(
            $source->load('dataset')->loadCount(['sourceFiles', 'checks'])
        ))->response()->setStatusCode(201);
    }

    public function show(StatisticalSource $source): SourceResource
    {
        return new SourceResource($source->load('dataset')->loadCount(['sourceFiles', 'checks']));
    }

    public function update(
        UpdateSourceRequest $request,
        StatisticalSource $source
    ): SourceResource|JsonResponse {
        $validated = $request->validated();

        if (isset($validated['dataset_public_id'])) {
            $dataset = StatisticalDataset::query()
                ->where('public_id', $validated['dataset_public_id'])
                ->firstOrFail();

            if ($dataset->id !== $source->dataset_id && $source->sourceFiles()->exists()) {
                return $this->errors->respond(new SourceFileIngestionException(
                    SourceFileErrorCode::SourceDatasetMismatch,
                    'Source dataset cannot be changed after a source file has been stored.'
                ));
            }

            $validated['dataset_id'] = $dataset->id;
            unset($validated['dataset_public_id']);
        }

        $source->update($validated);

        return new SourceResource(
            $source->refresh()->load('dataset')->loadCount(['sourceFiles', 'checks'])
        );
    }
}
