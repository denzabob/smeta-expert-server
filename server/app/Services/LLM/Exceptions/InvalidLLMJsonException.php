<?php

declare(strict_types=1);

namespace App\Services\LLM\Exceptions;

/**
 * Исключение: невалидный JSON от LLM
 */
class InvalidLLMJsonException extends LLMException
{
    protected string $errorType = 'invalid_json';
    protected string $rawContent;

    public function __construct(
        string $message,
        string $rawContent,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->rawContent = $rawContent;
    }

    public function getRawContent(): string
    {
        return $this->rawContent;
    }
}
