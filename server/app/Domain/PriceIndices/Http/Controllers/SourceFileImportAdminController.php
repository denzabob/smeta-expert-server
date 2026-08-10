<?php

namespace App\Domain\PriceIndices\Http\Controllers;

use App\Domain\PriceIndices\Application\Services\QueueStatisticalImport;
use App\Domain\PriceIndices\Application\Services\QueueStatisticalImportPreview;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesApiException;
use App\Domain\PriceIndices\Domain\SourceFiles\StatisticalSourceFile;
use App\Domain\PriceIndices\Http\PriceIndicesErrorResponder;
use App\Domain\PriceIndices\Http\Resources\StatisticalImportPreviewResource;
use App\Domain\PriceIndices\Http\Resources\StatisticalImportResource;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SourceFileImportAdminController extends Controller
{
    public function __construct(
        private readonly QueueStatisticalImportPreview $preview,
        private readonly QueueStatisticalImport $queue,
        private readonly PriceIndicesErrorResponder $errors,
    ) {
    }

    public function preview(Request $request, StatisticalSourceFile $sourceFile): JsonResponse
    {
        try {
            $result = $this->preview->execute($sourceFile, $request->user());

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

    public function store(Request $request, StatisticalSourceFile $sourceFile): JsonResponse
    {
        try {
            $import = $this->queue->execute($sourceFile, $request->user());

            return (new StatisticalImportResource($import->load([
                'dataset', 'sourceFile', 'activePointer', 'supersedes',
            ])))->additional(['meta' => ['queued' => true]])
                ->response()
                ->setStatusCode(202);
        } catch (PriceIndicesApiException $exception) {
            return $this->errors->respond($exception);
        }
    }
}
