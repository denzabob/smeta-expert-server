<?php

namespace App\Domain\PriceIndices\Http\Controllers;

use App\Domain\PriceIndices\Application\Services\ListStatisticalImportIssues;
use App\Domain\PriceIndices\Application\Services\ListStatisticalImportObservations;
use App\Domain\PriceIndices\Application\Services\ListStatisticalImports;
use App\Domain\PriceIndices\Application\Services\PublishStatisticalImportForAdmin;
use App\Domain\PriceIndices\Application\Services\RetryStatisticalImport;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesApiException;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Domain\PriceIndices\Http\PriceIndicesErrorResponder;
use App\Domain\PriceIndices\Http\Requests\StatisticalImportIndexRequest;
use App\Domain\PriceIndices\Http\Requests\StatisticalImportIssueIndexRequest;
use App\Domain\PriceIndices\Http\Requests\StatisticalObservationIndexRequest;
use App\Domain\PriceIndices\Http\Resources\StatisticalImportIssueResource;
use App\Domain\PriceIndices\Http\Resources\StatisticalImportResource;
use App\Domain\PriceIndices\Http\Resources\StatisticalObservationResource;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class StatisticalImportAdminController extends Controller
{
    private const RELATIONS = ['dataset', 'sourceFile', 'activePointer', 'supersedes'];

    public function __construct(
        private readonly ListStatisticalImports $imports,
        private readonly ListStatisticalImportIssues $issues,
        private readonly ListStatisticalImportObservations $observations,
        private readonly PublishStatisticalImportForAdmin $publish,
        private readonly RetryStatisticalImport $retry,
        private readonly PriceIndicesErrorResponder $errors,
    ) {
    }

    public function index(StatisticalImportIndexRequest $request): AnonymousResourceCollection
    {
        return StatisticalImportResource::collection($this->imports->execute($request->validated()));
    }

    public function show(StatisticalImport $import): StatisticalImportResource
    {
        return new StatisticalImportResource($import->load(self::RELATIONS));
    }

    public function issues(
        StatisticalImportIssueIndexRequest $request,
        StatisticalImport $import,
    ): AnonymousResourceCollection {
        return StatisticalImportIssueResource::collection(
            $this->issues->execute($import, $request->validated())
        );
    }

    public function observations(
        StatisticalObservationIndexRequest $request,
        StatisticalImport $import,
    ): AnonymousResourceCollection {
        return StatisticalObservationResource::collection(
            $this->observations->execute($import, $request->validated())
        );
    }

    public function publish(Request $request, StatisticalImport $import): JsonResponse
    {
        try {
            $result = $this->publish->execute($import->loadMissing('dataset'), $request->user());

            return (new StatisticalImportResource($result->import->load(self::RELATIONS)))
                ->additional(['meta' => [
                    'previous_import_public_id' => $result->previousImportPublicId,
                    'superseded' => $result->previousImportPublicId !== null,
                ]])
                ->response();
        } catch (PriceIndicesApiException $exception) {
            return $this->errors->respond($exception);
        }
    }

    public function retry(Request $request, StatisticalImport $import): JsonResponse
    {
        try {
            $retry = $this->retry->execute($import, $request->user());

            return (new StatisticalImportResource($retry->load(self::RELATIONS)))
                ->additional(['meta' => ['queued' => true]])
                ->response()
                ->setStatusCode(202);
        } catch (PriceIndicesApiException $exception) {
            return $this->errors->respond($exception);
        }
    }
}
