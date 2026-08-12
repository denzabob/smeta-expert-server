<?php

namespace App\Domain\PriceIndices\Http\Controllers;

use App\Domain\PriceIndices\Application\Services\GetActiveStatisticalSeries;
use App\Domain\PriceIndices\Application\Services\ListActiveStatisticalSeries;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesApiException;
use App\Domain\PriceIndices\Http\PriceIndicesErrorResponder;
use App\Domain\PriceIndices\Http\Requests\UserStatisticalSeriesIndexRequest;
use App\Domain\PriceIndices\Http\Resources\UserStatisticalSeriesResource;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class UserStatisticalSeriesController extends Controller
{
    public function __construct(
        private readonly ListActiveStatisticalSeries $list,
        private readonly GetActiveStatisticalSeries $get,
        private readonly PriceIndicesErrorResponder $errors,
    ) {
    }

    public function index(UserStatisticalSeriesIndexRequest $request): JsonResponse
    {
        try {
            return UserStatisticalSeriesResource::collection($this->list->execute($request->validated()))->response();
        } catch (PriceIndicesApiException $exception) {
            return $this->errors->respond($exception);
        }
    }

    public function show(string $seriesPublicId): JsonResponse
    {
        try {
            return (new UserStatisticalSeriesResource($this->get->execute($seriesPublicId)->series))->response();
        } catch (PriceIndicesApiException $exception) {
            return $this->errors->respond($exception);
        }
    }
}
