<?php

namespace App\Domain\PriceIndices\Http\Controllers;

use App\Domain\PriceIndices\Application\Services\ExpireStatisticalImportPreviewIfNeeded;
use App\Domain\PriceIndices\Application\Services\RetryStatisticalImportPreview;
use App\Domain\PriceIndices\Domain\Enums\StatisticalImportPreviewStatus;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesApiException;
use App\Domain\PriceIndices\Domain\Previews\StatisticalImportPreview;
use App\Domain\PriceIndices\Http\PriceIndicesErrorResponder;
use App\Domain\PriceIndices\Http\Resources\ImportPreviewResource;
use App\Domain\PriceIndices\Http\Resources\StatisticalImportPreviewResource;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StatisticalImportPreviewAdminController extends Controller
{
    public function __construct(
        private readonly ExpireStatisticalImportPreviewIfNeeded $expire,
        private readonly RetryStatisticalImportPreview $retry,
        private readonly PriceIndicesErrorResponder $errors,
    ) {
    }

    public function show(StatisticalImportPreview $preview): StatisticalImportPreviewResource
    {
        return new StatisticalImportPreviewResource(
            $this->expire->execute($preview)->load(['dataset', 'sourceFile'])
        );
    }

    public function result(StatisticalImportPreview $preview): ImportPreviewResource|JsonResponse
    {
        try {
            $preview = $this->expire->execute($preview);

            if ($preview->status === StatisticalImportPreviewStatus::Ready) {
                return new ImportPreviewResource($preview->result_json ?? []);
            }

            [$code, $message] = match ($preview->status) {
                StatisticalImportPreviewStatus::Failed => [
                    'preview_failed',
                    'The preview failed and has no result.',
                ],
                StatisticalImportPreviewStatus::Expired => [
                    'preview_expired',
                    'The preview result has expired.',
                ],
                default => [
                    'preview_not_ready',
                    'The preview result is not ready.',
                ],
            };

            throw new PriceIndicesApiException($code, 409, $message);
        } catch (PriceIndicesApiException $exception) {
            return $this->errors->respond($exception);
        }
    }

    public function retry(Request $request, StatisticalImportPreview $preview): JsonResponse
    {
        try {
            $result = $this->retry->execute($preview, $request->user());

            return (new StatisticalImportPreviewResource(
                $result->preview->load(['dataset', 'sourceFile'])
            ))->additional(['meta' => [
                'queued' => $result->queued,
                'cached' => $result->cached,
                'reused' => $result->reused,
            ]])->response()->setStatusCode($result->httpStatus);
        } catch (PriceIndicesApiException $exception) {
            return $this->errors->respond($exception);
        }
    }
}
