<?php

declare(strict_types=1);

namespace Tests\Feature\Expert\Support;

use App\Services\LLM\LLMSettingsRepository;
use App\Services\LLM\LLMTaskProfileResolver;

trait ConfiguresExpertModeProfiles
{
    protected function configureExpertModeProfiles(): void
    {
        $this->configureExpertModeProfile(LLMTaskProfileResolver::EXPERT_FAST);
        $this->configureExpertModeProfile(LLMTaskProfileResolver::EXPERT_DEEP);
    }

    protected function configureExpertModeProfile(string $task, bool $enabled = true): void
    {
        app(LLMSettingsRepository::class)->saveTaskProfile($task, [
            'provider' => 'routerai',
            'model' => 'openai/gpt-4o',
            'enabled' => $enabled,
            'fallback_policy' => 'none',
        ]);
    }

    protected function disableExpertDeepProfile(): void
    {
        $this->configureExpertModeProfile(LLMTaskProfileResolver::EXPERT_DEEP, false);
    }
}
