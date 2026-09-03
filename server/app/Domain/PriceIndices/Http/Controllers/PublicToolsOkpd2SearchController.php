<?php

namespace App\Domain\PriceIndices\Http\Controllers;

use App\Domain\PriceIndices\Application\Services\SearchPublicOkpd2;
use App\Domain\PriceIndices\Http\PublicToolsErrorResponder;
use App\Domain\PriceIndices\Http\Requests\PublicToolsIndexSeriesSearchRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PublicToolsOkpd2SearchController extends Controller
{
    public function __invoke(
        PublicToolsIndexSeriesSearchRequest $request,
        SearchPublicOkpd2 $search,
        PublicToolsErrorResponder $errors,
    ): JsonResponse {
        $validated = $request->validated();

        try {
            return response()->json([
                'classifier' => ['name' => 'ОКПД2'],
                'items' => $search->execute(
                    (string) $validated['q'],
                    (int) ($validated['limit'] ?? 20),
                ),
            ]);
        } catch (Throwable $exception) {
            Log::error('Public tools OKPD2 search failed unexpectedly.', [
                'route' => 'okpd2.search',
                'exception' => $exception::class,
            ]);

            return $errors->respond($exception);
        }
    }
}
