<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Models\Expert\ExpertConversation;
use App\Models\Expert\ExpertMessage;
use Illuminate\Contracts\Cache\Lock;

final class ExpertChatStreamingRun
{
    /** @param array<string, mixed> $registryRun */
    public function __construct(
        public readonly ExpertConversation $conversation,
        public readonly ExpertMessage $userMessage,
        public readonly ?ExpertChatMaterialContext $materialContext,
        /** @var list<string> */
        public readonly array $materialPublicIds,
        public readonly array $registryRun,
        public readonly Lock $lock,
        public readonly ?ExpertMessage $existingAssistant = null,
        public readonly bool $isContinuation = false,
    ) {}

    public function runId(): string
    {
        return (string) $this->registryRun['run_id'];
    }
}
