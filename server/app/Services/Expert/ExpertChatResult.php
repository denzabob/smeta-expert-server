<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Models\Expert\ExpertMessage;

final class ExpertChatResult
{
    public function __construct(
        public readonly ExpertMessage $userMessage,
        public readonly ExpertMessage $assistantMessage,
        public readonly bool $assistantWasCreated,
    ) {
    }
}
