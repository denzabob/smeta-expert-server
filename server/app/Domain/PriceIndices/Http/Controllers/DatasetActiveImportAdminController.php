<?php

namespace App\Domain\PriceIndices\Http\Controllers;

use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Http\Resources\StatisticalImportResource;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class DatasetActiveImportAdminController extends Controller
{
    public function __invoke(StatisticalDataset $dataset): StatisticalImportResource|JsonResponse
    {
        $pointer = $dataset->activeImport()->with([
            'import.dataset', 'import.sourceFile', 'import.activePointer', 'import.supersedes',
        ])->first();

        if ($pointer === null) {
            return response()->json(['data' => null]);
        }

        return new StatisticalImportResource($pointer->import);
    }
}
