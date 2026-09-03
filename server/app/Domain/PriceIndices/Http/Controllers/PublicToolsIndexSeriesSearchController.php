<?php

namespace App\Domain\PriceIndices\Http\Controllers;

use App\Domain\PriceIndices\Application\Services\SearchPublicToolIndexSeries;
use App\Domain\PriceIndices\Http\PublicToolsErrorResponder;
use App\Domain\PriceIndices\Http\Requests\PublicToolsIndexSeriesSearchRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PublicToolsIndexSeriesSearchController extends Controller
{
    public function __invoke(
        PublicToolsIndexSeriesSearchRequest $request,
        SearchPublicToolIndexSeries $search,
        PublicToolsErrorResponder $errors,
    ): JsonResponse {
        $validated = $request->validated();

        try {
            return response()->json([
                'items' => $search->execute(
                    (string) $validated['q'],
                    isset($validated['family']) && $validated['family'] !== null ? (string) $validated['family'] : null,
                    (int) ($validated['limit'] ?? 10),
                ),
            ]);
        } catch (Throwable $exception) {
            Log::error('Public tools index series search failed unexpectedly.', [
                'route' => 'index-series.search',
                'exception' => $exception::class,
            ]);

            return $errors->respond($exception);
        }
    }
}
