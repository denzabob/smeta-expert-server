<?php

namespace App\Domain\PriceIndices\Infrastructure\Parsing;

use App\Domain\PriceIndices\Domain\Exceptions\ClassifierParserException;

final class InspectedRarArchive implements ClassifierOuterArchive
{
    private bool $closed = false;

    /**
     * @param  list<ClassifierArchiveEntry>  $entries
     * @param  array<string, ClassifierArchiveEntry>  $entriesByName
     */
    public function __construct(
        private readonly string $archivePath,
        private readonly string $extractorBinary,
        private readonly int $maxListingBytes,
        private readonly int $commandTimeoutSeconds,
        private readonly ClassifierArchiveCommandRunner $runner,
        public readonly array $entries,
        private readonly array $entriesByName,
    ) {}

    public function type(): string
    {
        return 'rar';
    }

    /** @return list<ClassifierArchiveEntry> */
    public function entries(): array
    {
        return $this->entries;
    }

    /** @return list<string> */
    public function fileNames(): array
    {
        return array_values(array_map(
            static fn (ClassifierArchiveEntry $entry): string => $entry->name,
            array_filter($this->entries, static fn (ClassifierArchiveEntry $entry): bool => ! $entry->directory),
        ));
    }

    public function has(string $name): bool
    {
        return isset($this->entriesByName[$name]);
    }

    public function entry(string $name): ClassifierArchiveEntry
    {
        return $this->entriesByName[$name] ?? throw ClassifierParserException::fatal(
            'required_archive_entry_missing',
            'A required archive entry is missing.',
        );
    }

    public function materialize(string $name, string $temporaryPrefix): TemporaryParserFile
    {
        $entry = $this->entry($name);

        if ($entry->directory || $entry->link || $entry->special) {
            throw ClassifierParserException::fatal(
                'required_archive_entry_invalid',
                'A required archive entry is not a regular file.',
            );
        }

        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'prism-rar-'.bin2hex(random_bytes(12));

        if (! @mkdir($directory, 0700)) {
            throw ClassifierParserException::fatal(
                'temporary_file_failure',
                'Unable to allocate a temporary RAR extraction directory.',
            );
        }

        try {
            $result = $this->runner->run(
                [$this->extractorBinary, '-f', '-no-directory', '-o', $directory, $this->archivePath, $name],
                $this->maxListingBytes,
                $this->commandTimeoutSeconds,
            );

            if ($result->exitCode !== 0) {
                throw ClassifierParserException::fatal(
                    'corrupted_rar_entry',
                    'A required RAR entry cannot be extracted safely.',
                );
            }

            $extractedPath = $directory.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $name);

            if (is_link($extractedPath) || ! is_file($extractedPath) || ! is_readable($extractedPath)) {
                throw ClassifierParserException::fatal(
                    'corrupted_rar_entry',
                    'A required RAR entry was not materialized at its expected path.',
                );
            }

            $size = filesize($extractedPath);
            $crc32 = hash_file('crc32b', $extractedPath);

            if ($size !== $entry->uncompressedBytes
                || ($entry->crc32 !== null && ! hash_equals($entry->crc32, (string) $crc32))
            ) {
                throw ClassifierParserException::fatal(
                    'rar_entry_integrity_failure',
                    'A required RAR entry failed its size or CRC check.',
                );
            }

            $temporary = TemporaryParserFile::create($temporaryPrefix);

            if (! @copy($extractedPath, $temporary->path)) {
                $temporary->close();

                throw ClassifierParserException::fatal(
                    'temporary_file_failure',
                    'Unable to materialize a required parser entry.',
                );
            }

            return $temporary;
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function __destruct()
    {
        $this->close();
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        if ($items === false) {
            @rmdir($directory);

            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$item;

            if (is_dir($path) && ! is_link($path)) {
                $this->removeDirectory($path);
            } elseif (is_file($path) || is_link($path)) {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
