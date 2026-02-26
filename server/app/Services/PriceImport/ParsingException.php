<?php

namespace App\Services\PriceImport;

/**
 * Exception for parsing errors
 */
class ParsingException extends \Exception
{
    protected array $details = [];

    public function __construct(string $message, array $details = [], int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->details = $details;
    }

    public function getDetails(): array
    {
        return $this->details;
    }

    public function toArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'details' => $this->details,
        ];
    }
}
