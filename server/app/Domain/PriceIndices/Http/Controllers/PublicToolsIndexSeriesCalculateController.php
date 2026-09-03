<?php

namespace App\Domain\PriceIndices\Http\Controllers;

use App\Domain\PriceIndices\Application\Services\CalculatePublicStatisticalIndex;
use App\Domain\PriceIndices\Application\Services\PublicIndexFamilyRegistry;
use App\Domain\PriceIndices\Application\Support\DecimalMath;
use App\Domain\PriceIndices\Application\Support\PublicPriceIndexUrl;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesApiException;
use App\Domain\PriceIndices\Http\PublicToolsErrorResponder;
use App\Domain\PriceIndices\Http\Requests\PublicToolsIndexSeriesCalculateRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PublicToolsIndexSeriesCalculateController extends Controller
{
    public function __construct(
        private readonly CalculatePublicStatisticalIndex $calculator,
        private readonly PublicIndexFamilyRegistry $families,
        private readonly PublicPriceIndexUrl $urls,
        private readonly DecimalMath $decimal,
        private readonly PublicToolsErrorResponder $errors,
    ) {}

    public function __invoke(PublicToolsIndexSeriesCalculateRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $familyCode = (string) $validated['family'];
            $calculation = $this->calculator->execute(
                $familyCode,
                (string) $validated['slug'],
                (string) $validated['start_period'],
                (string) $validated['end_period'],
                $validated['amount'] ?? null,
            );
            $family = $this->families->get($familyCode);
            $amount = $calculation['amount'];
            $original = $amount === null ? null : (string) $amount['original'];
            $adjusted = $amount === null ? null : (string) $amount['adjusted'];

            return response()->json([
                'series' => [
                    'slug' => (string) $calculation['page']['slug'],
                    'family' => $family->code,
                    'family_label' => $family->publicLabel,
                    'title' => (string) ($calculation['page']['title'] ?? ''),
                    'code' => $calculation['page']['classifier']['code'] === null
                        ? null
                        : (string) $calculation['page']['classifier']['code'],
                    'detail_url' => $this->urls->detail((string) $calculation['page']['slug'], $family->code),
                ],
                'period' => [
                    'start' => (string) $calculation['period']['start'],
                    'end' => (string) $calculation['period']['end'],
                    'months' => (int) $calculation['period']['factors_count'],
                ],
                'result' => [
                    'factor' => (string) $calculation['coefficient'],
                    'change_percent' => (string) $calculation['change_percent'],
                    'amount' => $original,
                    'result_amount' => $adjusted,
                    'delta_amount' => $adjusted === null || $original === null
                        ? null
                        : $this->decimal->roundHalfUp(
                            $this->decimal->subtract($adjusted, $original),
                            DecimalMath::AMOUNT_SCALE,
                        ),
                ],
                'source' => [
                    'publisher' => (string) ($calculation['provenance']['provider'] ?? 'Росстат'),
                ],
            ], 200, ['Cache-Control' => 'no-store']);
        } catch (PriceIndicesApiException $exception) {
            return $this->errors->respond($exception);
        } catch (Throwable $exception) {
            Log::error('Public tools calculation failed unexpectedly.', [
                'route' => 'index-series.calculate',
                'family' => (string) ($validated['family'] ?? ''),
                'slug' => (string) ($validated['slug'] ?? ''),
                'exception' => $exception::class,
            ]);

            return $this->errors->respond($exception);
        }
    }
}
