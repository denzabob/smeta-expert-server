<?php

namespace App\Domain\PriceIndices\Application\Data;

use App\Domain\PriceIndices\Domain\Previews\StatisticalImportPreview;

final readonly class QueuedStatisticalImportPreview
{
    public function __construct(
        public StatisticalImportPreview $preview,
        public bool $queued,
        public bool $cached,
        public bool $reused,
        public int $httpStatus,
    ) {
    }
}
