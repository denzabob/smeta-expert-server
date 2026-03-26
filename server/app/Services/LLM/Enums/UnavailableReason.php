<?php

declare(strict_types=1);

namespace App\Services\LLM\Enums;

enum UnavailableReason: string
{
    case NOT_CONFIGURED = 'not_configured';
    case NO_API_KEY = 'no_api_key';
    case CIRCUIT_OPEN = 'circuit_open';
    case INVALID_CONFIG = 'invalid_config';
    case NONE = 'none';

    public function label(): string
    {
        return match ($this) {
            self::NOT_CONFIGURED => 'Провайдер не зарегистрирован',
            self::NO_API_KEY => 'API ключ не настроен',
            self::CIRCUIT_OPEN => 'Circuit breaker открыт',
            self::INVALID_CONFIG => 'Некорректная конфигурация',
            self::NONE => '',
        };
    }
}
