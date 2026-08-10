<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Application\Contracts\StatisticalSourceImporter;
use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesInvariantViolation;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Domain\PriceIndices\Domain\SourceFiles\StatisticalSourceFile;
use App\Domain\PriceIndices\Infrastructure\Import\ProducerPriceIndicesByProductImporter;

class StatisticalImporterRegistry
{
    /** @var array<string, StatisticalSourceImporter> */
    private array $importers;

    public function __construct(ProducerPriceIndicesByProductImporter $producerPriceIndices)
    {
        $this->importers = ['producer_price_indices_by_product' => $producerPriceIndices];
    }

    public function forSourceFile(StatisticalSourceFile $file): StatisticalSourceImporter
    {
        $dataset = $file->dataset()->firstOrFail();
        $importer = $this->importers[$dataset->code] ?? null;

        if ($importer === null || ! $importer->supports($dataset, $file)) {
            throw new PriceIndicesInvariantViolation('No statistical importer supports this dataset and source file.');
        }

        return $importer;
    }

    public function forImport(StatisticalImport $import): StatisticalSourceImporter
    {
        $dataset = $import->dataset()->firstOrFail();
        $file = $import->sourceFile()->firstOrFail();
        $importer = $this->importers[$dataset->code] ?? null;

        if ($importer === null
            || ! $importer->supports($dataset, $file)
            || $importer->code() !== $import->importer_code
            || $importer->version() !== $import->importer_version
        ) {
            throw new PriceIndicesInvariantViolation('The statistical import identity is unsupported.');
        }

        return $importer;
    }
}
