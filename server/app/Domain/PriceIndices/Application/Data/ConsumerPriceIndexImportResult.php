<?php

namespace App\Domain\PriceIndices\Application\Data;

final readonly class ConsumerPriceIndexImportResult
{
    public function __construct(
        public string $datasetCode,
        public string $sourcePublicId,
        public string $sourceSha256,
        public string $importPublicId,
        public string $importStatus,
        public bool $reused,
        public int $seriesCount,
        public int $observationsCount,
        public string $firstPeriod,
        public string $lastPeriod,
        public string $parserCode,
        public string $parserVersion,
        public string $importerCode,
        public string $importerVersion,
    ) {}

    /** @return array<string, bool|int|string> */
    public function toArray(): array
    {
        return [
            'dataset' => $this->datasetCode,
            'source_public_id' => $this->sourcePublicId,
            'source_sha256' => $this->sourceSha256,
            'import_public_id' => $this->importPublicId,
            'import_status' => $this->importStatus,
            'disposition' => $this->reused ? 'reused' : 'new',
            'series_count' => $this->seriesCount,
            'observations_count' => $this->observationsCount,
            'first_period' => $this->firstPeriod,
            'last_period' => $this->lastPeriod,
            'parser_code' => $this->parserCode,
            'parser_version' => $this->parserVersion,
            'importer_code' => $this->importerCode,
            'importer_version' => $this->importerVersion,
        ];
    }
}
