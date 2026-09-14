<?php

declare(strict_types=1);

namespace App\Services\LLM\Exceptions;

use App\Services\LLM\Enums\LLMCapability;
use RuntimeException;

final class LLMUnsupportedCapabilityException extends RuntimeException
{
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly LLMCapability $capability,
    ) {
        parent::__construct("Provider profile does not support {$capability->value}.");
    }
}
