<?php

namespace App\Domain\PriceIndices\Application\Services;

class ClassifierCandidatePersistenceObserver
{
    public const AFTER_VERSION_CREATE = 'after_version_create';

    public const AFTER_NODE_INSERT_BATCH = 'after_node_insert_batch';

    public const AFTER_NODE_INSERTS = 'after_node_inserts';

    public const AFTER_PARENT_UPDATE_BATCH = 'after_parent_update_batch';

    public const AFTER_PARENT_UPDATES = 'after_parent_updates';

    public const BEFORE_INTEGRITY_SUCCESS = 'before_integrity_success';

    public function reached(string $point, int $processedNodes = 0): void
    {
        // Intentionally empty. Tests may replace this collaborator to inject failures.
    }
}
