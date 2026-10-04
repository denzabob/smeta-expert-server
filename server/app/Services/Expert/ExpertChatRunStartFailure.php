<?php

declare(strict_types=1);

namespace App\Services\Expert;

use RuntimeException;
use Throwable;

final class ExpertChatRunStartFailure extends RuntimeException
{
    public function __construct(
        public readonly string $runId,
        Throwable $cause,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($cause->getMessage(), (int) $cause->getCode(), $cause);
    }
}
