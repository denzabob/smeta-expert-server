<?php

declare(strict_types=1);

namespace App\Services\LLM\Enums;

enum LLMErrorType: string
{
    case AUTH = 'auth';
    case CONFIG = 'config';
    case TIMEOUT = 'timeout';
    case RATE_LIMIT = 'rate_limit';
    case SERVER_ERROR = 'server_error';
    case NETWORK = 'network';
    case INVALID_RESPONSE = 'invalid_response';
    case UNKNOWN = 'unknown';

    /**
     * Допускается ли failover для этого типа ошибки
     */
    public function isFailoverAllowed(): bool
    {
        return match ($this) {
            self::AUTH, self::CONFIG => false,
            default => true,
        };
    }

    /**
     * Допускается ли retry для этого типа ошибки
     */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::TIMEOUT, self::SERVER_ERROR, self::NETWORK => true,
            default => false,
        };
    }

    /**
     * Человекочитаемое описание
     */
    public function label(): string
    {
        return match ($this) {
            self::AUTH => 'Authentication error',
            self::CONFIG => 'Configuration error',
            self::TIMEOUT => 'Request timeout',
            self::RATE_LIMIT => 'Rate limit exceeded',
            self::SERVER_ERROR => 'Server error (5xx)',
            self::NETWORK => 'Network error',
            self::INVALID_RESPONSE => 'Invalid response',
            self::UNKNOWN => 'Unknown error',
        };
    }
}
