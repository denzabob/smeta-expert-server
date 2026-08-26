<?php

namespace App\Domain\PriceIndices\Application\Services;

class ConsumerPriceIndexPersistenceObserver
{
    public const AFTER_REFERENCE_RESOLUTION = 'after_reference_resolution';

    public const AFTER_SERIES_RESOLUTION = 'after_series_resolution';

    public const AFTER_OBSERVATION_BATCH = 'after_observation_batch';

    public const BEFORE_READY_TRANSITION = 'before_ready_transition';

    public function reached(string $point, int $processedObservations = 0): void
    {
        // Intentionally empty. Tests may replace this collaborator to inject failures.
    }
}
