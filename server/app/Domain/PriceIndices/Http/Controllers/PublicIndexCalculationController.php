<?php

namespace App\Domain\PriceIndices\Http\Controllers;

use App\Domain\PriceIndices\Application\Services\CalculatePublicStatisticalIndex;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesApiException;
use App\Domain\PriceIndices\Http\PriceIndicesErrorResponder;
use App\Domain\PriceIndices\Http\Requests\CalculatePublicStatisticalIndexRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PublicIndexCalculationController extends Controller
{
    public function __construct(
        private readonly CalculatePublicStatisticalIndex $calculator,
        private readonly PriceIndicesErrorResponder $errors,
    ) {}

    public function __invoke(
        string $slug,
        CalculatePublicStatisticalIndexRequest $request,
    ): JsonResponse {
        $validated = $request->validated();

        try {
            $response = response()->json([
                'data' => $this->calculator->execute(
                    $slug,
                    $validated['start_period'],
                    $validated['end_period'],
                    $validated['amount'] ?? null,
                ),
            ]);
        } catch (PriceIndicesApiException $exception) {
            $response = $this->errors->respond($exception);
        } catch (Throwable $exception) {
            Log::error('Public Price Indices calculation failed unexpectedly.', [
                'slug' => $slug,
                'start_period' => $validated['start_period'],
                'end_period' => $validated['end_period'],
                'exception' => $exception::class,
            ]);
            $response = response()->json([
                'message' => 'Расчёт временно недоступен.',
                'code' => 'public_calculation_failed',
            ], 500);
        }

        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
