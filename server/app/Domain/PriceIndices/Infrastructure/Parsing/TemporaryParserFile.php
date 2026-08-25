<?php

namespace App\Domain\PriceIndices\Infrastructure\Parsing;

use App\Domain\PriceIndices\Domain\Exceptions\ClassifierParserException;

final class TemporaryParserFile
{
    private bool $closed = false;

    private function __construct(public readonly string $path) {}

    public static function create(string $prefix): self
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);

        if ($path === false) {
            throw ClassifierParserException::fatal(
                'temporary_file_failure',
                'Unable to allocate a temporary parser file.'
            );
        }

        return new self($path);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
