<?php

namespace App\Domain\PriceIndices\Infrastructure\Parsing;

interface ClassifierArchiveCommandRunner
{
    /** @param list<string> $command */
    public function run(array $command, int $maxOutputBytes, int $timeoutSeconds): ClassifierArchiveCommandResult;
}
