<?php

namespace App\Domain\PriceIndices\Infrastructure\Parsing;

final readonly class ClassifierArchiveCommandResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
    ) {}
}
