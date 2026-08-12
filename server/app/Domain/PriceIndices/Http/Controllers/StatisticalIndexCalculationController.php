<?php

namespace App\Domain\PriceIndices\Http\Controllers;

use App\Domain\PriceIndices\Application\Data\StatisticalCalculationInput;
use App\Domain\PriceIndices\Application\Services\CalculateStatisticalIndexChange;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesApiException;
use App\Domain\PriceIndices\Http\PriceIndicesErrorResponder;
use App\Domain\PriceIndices\Http\Requests\CalculateStatisticalIndexRequest;
use App\Domain\PriceIndices\Http\Resources\StatisticalCalculationResource;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

final class StatisticalIndexCalculationController extends Controller
{
    public function __construct(
        private readonly CalculateStatisticalIndexChange $calculator,
        private readonly PriceIndicesErrorResponder $errors,
    ) {
    }

    public function __invoke(CalculateStatisticalIndexRequest $request): JsonResponse
    {
        $validated = $request->validated();
        try {
            $result = $this->calculator->execute(new StatisticalCalculationInput(
                $validated['series_public_id'],
                $validated['start_period'],
                $validated['end_period'],
                $validated['base_amount'] ?? null,
            ));

            return (new StatisticalCalculationResource($result))->response();
        } catch (PriceIndicesApiException $exception) {
            return $this->errors->respond($exception);
        } catch (Throwable $exception) {
            Log::error('Price Indices calculation failed unexpectedly.', [
                'series_public_id' => $validated['series_public_id'],
                'start_period' => $validated['start_period'],
                'end_period' => $validated['end_period'],
                'exception' => $exception::class,
            ]);

            return response()->json([
                'message' => 'The calculation could not be completed.',
                'code' => 'calculation_failed',
            ], 500);
        }
    }
}
