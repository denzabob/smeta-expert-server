<?php

declare(strict_types=1);

namespace App\Services\Expert;

use RuntimeException;
use Throwable;

final class ExpertStorageException extends RuntimeException
{
    public const FILE_NOT_FOUND = 'FILE_NOT_FOUND';
    public const STORAGE_UNAVAILABLE = 'STORAGE_UNAVAILABLE';
    public const STORAGE_BACKEND_UNAVAILABLE = 'STORAGE_BACKEND_UNAVAILABLE';
    public const READ_FAILED = 'READ_FAILED';
    public const WRITE_FAILED = 'WRITE_FAILED';
    public const DELETE_FAILED = 'DELETE_FAILED';
    public const INVALID_OBJECT_KEY = 'INVALID_OBJECT_KEY';

    public readonly ?string $sourceExceptionClass;

    public function __construct(
        public readonly string $failureCode,
        ?Throwable $previous = null,
    ) {
        $this->sourceExceptionClass = $previous === null ? null : $previous::class;

        // Do not retain the provider exception as the previous exception:
        // framework exception reporters may serialize its endpoint or request details.
        parent::__construct('Expert storage operation failed: '.$failureCode.'.');
    }
}
