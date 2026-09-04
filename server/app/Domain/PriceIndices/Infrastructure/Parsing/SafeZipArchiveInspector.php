<?php

namespace App\Domain\PriceIndices\Infrastructure\Parsing;

use App\Domain\PriceIndices\Application\Data\ZipSafetyLimits;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierParserException;
use ZipArchive;

class SafeZipArchiveInspector
{
    public function __construct(private readonly ZipEntryNamePolicy $entryNames) {}

    public function open(string $absolutePath, ZipSafetyLimits $limits): InspectedZipArchive
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            throw ClassifierParserException::fatal(
                'unreadable_zip',
                'The classifier archive is missing or unreadable.'
            );
        }

        $archive = new ZipArchive;
        $opened = $archive->open($absolutePath, ZipArchive::RDONLY | ZipArchive::CHECKCONS);

        if ($opened !== true) {
            throw ClassifierParserException::fatal(
                'unreadable_zip',
                'The classifier archive is not a consistent ZIP file.'
            );
        }

        try {
            if ($archive->numFiles < 1 || $archive->numFiles > $limits->maxEntries) {
                throw ClassifierParserException::fatal(
                    'zip_entry_count_limit',
                    'The classifier archive entry count is outside the allowed bounds.'
                );
            }

            $totalUncompressed = 0;
            $totalCompressed = 0;
            $canonicalNames = [];
            $entries = [];
            $entriesByName = [];

            for ($index = 0; $index < $archive->numFiles; $index++) {
                $stat = $archive->statIndex($index, ZipArchive::FL_UNCHANGED);

                if ($stat === false) {
                    throw ClassifierParserException::fatal(
                        'corrupted_zip_entry',
                        'The classifier archive contains an unreadable entry.'
                    );
                }

                $name = (string) ($stat['name'] ?? '');
                $canonicalName = $this->entryNames->canonical($name);

                if (isset($canonicalNames[$canonicalName])) {
                    throw ClassifierParserException::fatal(
                        'duplicate_zip_entry',
                        'The classifier archive contains duplicate or ambiguous entry names.'
                    );
                }

                $canonicalNames[$canonicalName] = true;
                $size = (int) ($stat['size'] ?? -1);
                $compressedSize = (int) ($stat['comp_size'] ?? -1);

                if ($size < 0 || $compressedSize < 0) {
                    throw ClassifierParserException::fatal(
                        'corrupted_zip_entry',
                        'The classifier archive contains invalid entry metadata.'
                    );
                }

                if ($size > $limits->maxSingleEntryUncompressedBytes) {
                    throw ClassifierParserException::fatal(
                        'zip_entry_size_limit',
                        'A classifier archive entry exceeds its uncompressed size limit.'
                    );
                }

                $totalUncompressed += $size;
                $totalCompressed += $compressedSize;

                if ($totalUncompressed > $limits->maxTotalUncompressedBytes) {
                    throw ClassifierParserException::fatal(
                        'zip_total_size_limit',
                        'The classifier archive exceeds its total uncompressed size limit.'
                    );
                }

                if ($totalCompressed > $limits->maxTotalCompressedBytes) {
                    throw ClassifierParserException::fatal(
                        'zip_total_compressed_size_limit',
                        'The classifier archive exceeds its total compressed size limit.'
                    );
                }

                if ($size > 0 && ($size / max(1, $compressedSize)) > $limits->maxCompressionRatio) {
                    throw ClassifierParserException::fatal(
                        'zip_compression_ratio_limit',
                        'A classifier archive entry has an unsafe compression ratio.'
                    );
                }

                if ((int) ($stat['encryption_method'] ?? ZipArchive::EM_NONE) !== ZipArchive::EM_NONE) {
                    throw ClassifierParserException::fatal(
                        'encrypted_zip_entry',
                        'Encrypted classifier archive entries are not accepted.'
                    );
                }

                $this->assertRegularOrDirectory($archive, $index);
                $directory = str_ends_with(str_replace('\\', '/', $name), '/');
                $entry = new InspectedZipEntry(
                    index: $index,
                    name: $name,
                    uncompressedBytes: $size,
                    compressedBytes: $compressedSize,
                    crc32: sprintf('%08x', (int) ($stat['crc'] ?? 0)),
                    directory: $directory,
                );
                $entries[] = $entry;
                $entriesByName[$name] = $entry;
            }

            return new InspectedZipArchive($archive, $entries, $entriesByName);
        } catch (\Throwable $exception) {
            $archive->close();

            throw $exception;
        }
    }

    private function assertRegularOrDirectory(ZipArchive $archive, int $index): void
    {
        $operatingSystem = 0;
        $attributes = 0;

        if (! $archive->getExternalAttributesIndex(
            $index,
            $operatingSystem,
            $attributes,
            ZipArchive::FL_UNCHANGED,
        )) {
            return;
        }

        if ($operatingSystem !== ZipArchive::OPSYS_UNIX) {
            return;
        }

        $mode = ($attributes >> 16) & 0xFFFF;
        $type = $mode & 0170000;

        if (! in_array($type, [0, 0040000, 0100000], true)) {
            throw ClassifierParserException::fatal(
                'special_zip_entry',
                'Symlink and special classifier archive entries are not accepted.'
            );
        }
    }
}
