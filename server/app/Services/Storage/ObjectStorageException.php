<?php

namespace App\Services\Storage;

use RuntimeException;

class ObjectStorageException extends RuntimeException
{
    public const BACKEND_UNAVAILABLE = 'STORAGE_BACKEND_UNAVAILABLE';
    public const FILE_NOT_FOUND = 'STORAGE_FILE_NOT_FOUND';

    public function __construct(public readonly string $failureCode, ?\Throwable $previous = null)
    {
        parent::__construct($failureCode, 0, $previous);
    }
}
