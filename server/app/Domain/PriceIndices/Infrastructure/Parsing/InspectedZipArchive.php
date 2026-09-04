<?php

namespace App\Domain\PriceIndices\Infrastructure\Parsing;

use App\Domain\PriceIndices\Domain\Exceptions\ClassifierParserException;
use ZipArchive;

final class InspectedZipArchive implements ClassifierOuterArchive
{
    private bool $closed = false;

    /**
     * @param  list<ClassifierArchiveEntry>  $entries
     * @param  array<string, ClassifierArchiveEntry>  $entriesByName
     */
    public function __construct(
        private readonly ZipArchive $archive,
        public readonly array $entries,
        private readonly array $entriesByName,
    ) {}

    public function type(): string
    {
        return 'zip';
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
            fn (ClassifierArchiveEntry $entry): string => $entry->name,
            array_filter($this->entries, fn (ClassifierArchiveEntry $entry): bool => ! $entry->directory),
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
            'A required archive entry is missing.'
        );
    }

    public function materialize(string $name, string $temporaryPrefix): TemporaryParserFile
    {
        $entry = $this->entry($name);

        if ($entry->directory) {
            throw ClassifierParserException::fatal(
                'required_archive_entry_invalid',
                'A required archive entry is not a regular file.'
            );
        }

        $input = $this->archive->getStream($name);

        if ($input === false) {
            throw ClassifierParserException::fatal(
                'corrupted_zip_entry',
                'A required archive entry cannot be read.'
            );
        }

        $temporary = TemporaryParserFile::create($temporaryPrefix);
        $output = @fopen($temporary->path, 'wb');

        if ($output === false) {
            fclose($input);
            $temporary->close();

            throw ClassifierParserException::fatal(
                'temporary_file_failure',
                'Unable to open a temporary parser file.'
            );
        }

        $bytes = 0;
        $crc = hash_init('crc32b');

        try {
            while (true) {
                $chunk = fread($input, 65_536);

                if ($chunk === false) {
                    throw ClassifierParserException::fatal(
                        'corrupted_zip_entry',
                        'A required archive entry cannot be read safely.'
                    );
                }

                if ($chunk === '') {
                    break;
                }

                $bytes += strlen($chunk);

                if ($bytes > $entry->uncompressedBytes) {
                    throw ClassifierParserException::fatal(
                        'zip_entry_size_mismatch',
                        'An archive entry expanded beyond its declared size.'
                    );
                }

                hash_update($crc, $chunk);

                if (fwrite($output, $chunk) !== strlen($chunk)) {
                    throw ClassifierParserException::fatal(
                        'temporary_file_failure',
                        'Unable to materialize a required parser entry.'
                    );
                }
            }
        } catch (\Throwable $exception) {
            $temporary->close();

            throw $exception;
        } finally {
            fclose($input);
            fclose($output);
        }

        if ($bytes !== $entry->uncompressedBytes
            || ! is_string($entry->crc32)
            || ! hash_equals($entry->crc32, hash_final($crc))
        ) {
            $temporary->close();

            throw ClassifierParserException::fatal(
                'zip_entry_integrity_failure',
                'A required archive entry failed its size or CRC check.'
            );
        }

        return $temporary;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->archive->close();
    }

    public function __destruct()
    {
        $this->close();
    }
}
