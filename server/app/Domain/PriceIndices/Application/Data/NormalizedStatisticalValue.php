<?php

namespace App\Domain\PriceIndices\Application\Data;

use App\Domain\PriceIndices\Domain\Enums\StatisticalObservationMissingReason;

final readonly class NormalizedStatisticalValue
{
    public function __construct(
        public ?string $value,
        public ?StatisticalObservationMissingReason $missingReason,
        public string $raw,
        public ?string $footnoteMarker = null,
        public bool $specialFootnoted = false,
    ) {
    }
}
