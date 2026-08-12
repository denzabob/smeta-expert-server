<?php

namespace App\Domain\PriceIndices\Application\Data;

final readonly class PublicSeriesRefreshResult
{
    /** @param list<array<string, int|string|null>> $datasets */
    public function __construct(
        public int $seriesScanned,
        public int $indexable,
        public int $nonIndexable,
        public int $created,
        public int $updated,
        public int $unchanged,
        public int $failed,
        public int $stale,
        public bool $dryRun,
        public array $datasets,
    ) {
    }
}
