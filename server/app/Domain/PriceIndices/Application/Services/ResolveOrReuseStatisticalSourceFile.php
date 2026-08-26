<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Application\Data\IngestSourceFileData;
use App\Domain\PriceIndices\Application\Data\ParsedConsumerPriceIndexSnapshot;
use App\Domain\PriceIndices\Application\Data\ResolvedStatisticalSourceFile;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesInvariantViolation;
use App\Domain\PriceIndices\Domain\Exceptions\SourceFileDuplicate;

final class ResolveOrReuseStatisticalSourceFile
{
    public function __construct(private readonly IngestSourceFile $ingest) {}

    public function execute(IngestSourceFileData $data): ResolvedStatisticalSourceFile
    {
        if ($data->dataset->code !== ParsedConsumerPriceIndexSnapshot::DATASET_CODE
            || $data->dataset->classifier_code !== ParsedConsumerPriceIndexSnapshot::CLASSIFIER_CODE
        ) {
            throw new PriceIndicesInvariantViolation(
                'Controlled source reuse is only available for the CPI dataset.'
            );
        }

        try {
            return new ResolvedStatisticalSourceFile($this->ingest->execute($data), false);
        } catch (SourceFileDuplicate $exception) {
            return new ResolvedStatisticalSourceFile($exception->existingFile->refresh(), true);
        }
    }
}
