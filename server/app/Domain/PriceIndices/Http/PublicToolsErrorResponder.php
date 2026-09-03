<?php

namespace App\Domain\PriceIndices\Http;

use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesApiException;
use Illuminate\Http\JsonResponse;
use Throwable;

final class PublicToolsErrorResponder
{
    public function respond(Throwable $exception): JsonResponse
    {
        if ($exception instanceof PriceIndicesApiException) {
            [$code, $status, $message] = $this->map($exception);

            return response()->json([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                ],
            ], $status, ['Cache-Control' => 'no-store']);
        }

        return response()->json([
            'error' => [
                'code' => 'SERVICE_UNAVAILABLE',
                'message' => 'Сервис данных временно недоступен. Попробуйте ещё раз.',
            ],
        ], 503, ['Cache-Control' => 'no-store']);
    }

    /** @return array{string, int, string} */
    private function map(PriceIndicesApiException $exception): array
    {
        return match ($exception->errorCode) {
            'public_series_not_available' => [
                'SERIES_NOT_FOUND',
                404,
                'Выбранный статистический ряд не найден.',
            ],
            'period_before_available_range',
            'period_after_available_range',
            'incomplete_observation_chain' => [
                'PERIOD_NOT_AVAILABLE',
                422,
                'Для выбранного периода недостаточно опубликованных данных.',
            ],
            'period_too_long' => [
                'PERIOD_TOO_LONG',
                422,
                'Период расчёта не может быть длиннее 120 месяцев.',
            ],
            'invalid_period_range' => [
                'INVALID_PERIOD',
                422,
                'Конечный месяц должен быть позже начального.',
            ],
            'unsupported_series_calculation' => [
                'SERIES_NOT_CALCULABLE',
                422,
                'Для выбранного ряда расчёт за период недоступен.',
            ],
            'public_snapshot_unavailable',
            'calculation_integrity_error' => [
                'SERVICE_UNAVAILABLE',
                503,
                'Сервис данных временно недоступен. Попробуйте ещё раз.',
            ],
            'classifier_unavailable' => [
                'CLASSIFIER_UNAVAILABLE',
                503,
                'Классификатор временно недоступен. Попробуйте ещё раз.',
            ],
            default => [
                'PUBLIC_API_ERROR',
                $exception->httpStatus >= 400 && $exception->httpStatus < 500 ? $exception->httpStatus : 503,
                'Сервис данных временно недоступен. Попробуйте ещё раз.',
            ],
        };
    }
}
