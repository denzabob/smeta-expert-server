<?php

declare(strict_types=1);

namespace App\Services\LLM\Enums;

enum CircuitState: string
{
    case CLOSED = 'closed';
    case OPEN = 'open';
    case HALF_OPEN = 'half_open';

    public function label(): string
    {
        return match ($this) {
            self::CLOSED => 'Healthy',
            self::OPEN => 'Down',
            self::HALF_OPEN => 'Recovering',
        };
    }
}
