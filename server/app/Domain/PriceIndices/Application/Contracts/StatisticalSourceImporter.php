<?php

namespace App\Domain\PriceIndices\Application\Contracts;

use App\Domain\PriceIndices\Application\Data\ImportExecutionResult;
use App\Domain\PriceIndices\Application\Data\ImportPreviewResult;
use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Domain\PriceIndices\Domain\SourceFiles\StatisticalSourceFile;

interface StatisticalSourceImporter
{
    public function code(): string;

    public function version(): string;

    public function supports(StatisticalDataset $dataset, StatisticalSourceFile $file): bool;

    public function preview(StatisticalSourceFile $file): ImportPreviewResult;

    public function import(StatisticalImport $import): ImportExecutionResult;
}
