<?php

declare(strict_types=1);

namespace App\Services\LLM\Exceptions;

use Exception;

/**
 * Базовое исключение для LLM модуля
 */
class LLMException extends Exception
{
    protected string $errorType = 'unknown';

    public function getErrorType(): string
    {
        return $this->errorType;
    }
}
