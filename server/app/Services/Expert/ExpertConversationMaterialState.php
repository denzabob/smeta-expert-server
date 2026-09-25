<?php

declare(strict_types=1);

namespace App\Services\Expert;

/** A read model derived from user attachments, snapshots and the active pivot. */
final readonly class ExpertConversationMaterialState
{
    /** @param list<string> $lastResolvedSourceSet @param list<string> $lastPrimarySourceSet @param list<string> $lastComparisonSourceSet @param list<ExpertConversationSourceSet> $recentSourceSets @param list<string> $activeResearchSet @param list<ExpertAttachmentBatch> $recentAttachmentBatches */
    public function __construct(
        public ?ExpertAttachmentBatch $lastCurrentBatch,
        public array $lastResolvedSourceSet,
        public array $lastPrimarySourceSet,
        public array $lastComparisonSourceSet,
        public array $recentSourceSets,
        public array $activeResearchSet,
        public array $recentAttachmentBatches,
    ) {}
}
