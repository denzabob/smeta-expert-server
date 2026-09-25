<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Models\Expert\ExpertConversation;

interface ExpertSemanticContextResolver
{
    /** @param list<ExpertContextCandidate> $candidates @return array<string, mixed> */
    public function resolve(
        ExpertConversation $conversation,
        string $query,
        ExpertTaskIntent $intent,
        ExpertConversationMaterialState $state,
        array $candidates,
        ExpertContextConstraints $constraints,
    ): array;
}
