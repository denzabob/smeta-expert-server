<?php

namespace App\Domain\PriceIndices\Infrastructure\Parsing;

interface ClassifierOuterArchive
{
    public function type(): string;

    /** @return list<ClassifierArchiveEntry> */
    public function entries(): array;

    /** @return list<string> */
    public function fileNames(): array;

    public function has(string $name): bool;

    public function entry(string $name): ClassifierArchiveEntry;

    public function materialize(string $name, string $temporaryPrefix): TemporaryParserFile;

    public function close(): void;
}
