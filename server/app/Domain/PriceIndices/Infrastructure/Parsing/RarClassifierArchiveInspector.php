<?php

namespace App\Domain\PriceIndices\Infrastructure\Parsing;

use App\Domain\PriceIndices\Application\Data\ZipSafetyLimits;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierParserException;
use JsonException;

class RarClassifierArchiveInspector
{
    private const SIGNATURE = "Rar!\x1a\x07\x01\x00";

    /** @var list<string> */
    private const LSAR_TEST_RESULTS = [
        'ok',
        'not_tested',
        'not_supported',
        'wrong_password',
        'unpacking_failed',
        'wrong_size',
        'no_checksum',
        'wrong_checksum',
    ];

    public function __construct(
        private readonly ClassifierArchiveCommandRunner $runner,
        private readonly ZipEntryNamePolicy $entryNames,
    ) {}

    /** @param array<string, mixed> $configuration */
    public function open(
        string $absolutePath,
        ZipSafetyLimits $limits,
        array $configuration,
    ): InspectedRarArchive {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            throw ClassifierParserException::fatal(
                'unreadable_rar',
                'The classifier RAR archive is missing or unreadable.',
            );
        }

        $handle = @fopen($absolutePath, 'rb');

        if ($handle === false) {
            throw ClassifierParserException::fatal(
                'unreadable_rar',
                'The classifier RAR archive is missing or unreadable.',
            );
        }

        try {
            $signature = fread($handle, strlen(self::SIGNATURE));
        } finally {
            fclose($handle);
        }

        if ($signature !== self::SIGNATURE) {
            throw ClassifierParserException::fatal(
                'invalid_rar_signature',
                'The classifier artifact does not contain a RAR5 signature.',
            );
        }

        $inspectorBinary = $this->binary($configuration['inspector_binary'] ?? null);
        $extractorBinary = $this->binary($configuration['extractor_binary'] ?? null);
        $maxEntries = $this->positiveInt($configuration['max_entries'] ?? null);
        $maxListingBytes = $this->positiveInt($configuration['max_listing_bytes'] ?? null);
        $timeoutSeconds = $this->positiveInt($configuration['command_timeout_seconds'] ?? null);

        $result = $this->runner->run(
            [$inspectorBinary, '-test', '-json', '-no-recursion', $absolutePath],
            $maxListingBytes,
            $timeoutSeconds,
        );

        if ($result->exitCode !== 0) {
            throw ClassifierParserException::fatal(
                'unreadable_rar',
                'The classifier RAR archive could not be inspected consistently.',
            );
        }

        try {
            $listing = json_decode($result->stdout, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw ClassifierParserException::fatal(
                'invalid_rar_listing',
                'The trusted RAR inspector returned invalid listing data.',
                previous: $exception,
            );
        }

        if (! is_array($listing) || ! is_array($listing['lsarContents'] ?? null)) {
            throw ClassifierParserException::fatal(
                'invalid_rar_listing',
                'The trusted RAR inspector returned an unexpected listing shape.',
            );
        }

        $contents = $listing['lsarContents'];

        if ($contents === [] || count($contents) > $maxEntries) {
            throw ClassifierParserException::fatal(
                'rar_entry_count_limit',
                'The classifier RAR entry count is outside the allowed bounds.',
            );
        }

        $entries = [];
        $entriesByName = [];
        $canonicalNames = [];
        $totalUncompressed = 0;
        $totalCompressed = 0;

        foreach ($contents as $index => $rawEntry) {
            if (! is_array($rawEntry) || ! is_string($rawEntry['XADFileName'] ?? null)) {
                throw ClassifierParserException::fatal(
                    'invalid_rar_listing',
                    'The trusted RAR inspector returned an invalid entry.',
                );
            }

            $name = $rawEntry['XADFileName'];
            $canonicalName = $this->entryNames->canonical($name);

            if (isset($canonicalNames[$canonicalName])) {
                throw ClassifierParserException::fatal(
                    'duplicate_rar_entry',
                    'The classifier RAR contains duplicate or ambiguous entry names.',
                );
            }

            $canonicalNames[$canonicalName] = true;
            $uncompressed = $this->nonNegativeInt($rawEntry['XADFileSize'] ?? null);
            $compressed = $this->nonNegativeInt($rawEntry['XADCompressedSize'] ?? null);
            $directory = $this->truthy($rawEntry['XADIsDirectory'] ?? false);
            $encrypted = $this->truthy($rawEntry['XADIsEncrypted'] ?? false);
            $link = $this->truthy($rawEntry['XADIsSymbolicLink'] ?? false)
                || $this->truthy($rawEntry['XADIsLink'] ?? false)
                || $this->truthy($rawEntry['XADIsHardLink'] ?? false)
                || array_key_exists('XADLinkDestination', $rawEntry);
            $special = $this->truthy($rawEntry['XADIsSpecialFile'] ?? false);

            if ($uncompressed > $limits->maxSingleEntryUncompressedBytes) {
                throw ClassifierParserException::fatal(
                    'rar_entry_size_limit',
                    'A classifier RAR entry exceeds its uncompressed size limit.',
                );
            }

            $totalUncompressed += $uncompressed;
            $totalCompressed += $compressed;

            if ($totalUncompressed > $limits->maxTotalUncompressedBytes) {
                throw ClassifierParserException::fatal(
                    'rar_total_size_limit',
                    'The classifier RAR exceeds its total uncompressed size limit.',
                );
            }

            if ($totalCompressed > $limits->maxTotalCompressedBytes) {
                throw ClassifierParserException::fatal(
                    'rar_total_compressed_size_limit',
                    'The classifier RAR exceeds its total compressed size limit.',
                );
            }

            if ($uncompressed > 0 && ($uncompressed / max(1, $compressed)) > $limits->maxCompressionRatio) {
                throw ClassifierParserException::fatal(
                    'rar_compression_ratio_limit',
                    'A classifier RAR entry has an unsafe compression ratio.',
                );
            }

            if ($encrypted) {
                throw ClassifierParserException::fatal(
                    'encrypted_rar_entry',
                    'Encrypted classifier RAR entries are not accepted.',
                );
            }

            if ($link || $special) {
                throw ClassifierParserException::fatal(
                    'special_rar_entry',
                    'Symlink and special classifier RAR entries are not accepted.',
                );
            }

            $this->assertIntegrityTestPassed($rawEntry);

            $entry = new ClassifierArchiveEntry(
                index: (int) $index,
                name: $name,
                uncompressedBytes: $uncompressed,
                compressedBytes: $compressed,
                directory: $directory,
                encrypted: $encrypted,
                link: $link,
                special: $special,
                crc32: $this->crc32($rawEntry['RAR5CRC32'] ?? null),
            );
            $entries[] = $entry;
            $entriesByName[$name] = $entry;
        }

        return new InspectedRarArchive(
            archivePath: $absolutePath,
            extractorBinary: $extractorBinary,
            maxListingBytes: $maxListingBytes,
            commandTimeoutSeconds: $timeoutSeconds,
            runner: $this->runner,
            entries: $entries,
            entriesByName: $entriesByName,
        );
    }

    private function binary(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '' || str_contains($value, "\0")) {
            throw ClassifierParserException::fatal(
                'invalid_rar_command_configuration',
                'The trusted RAR command configuration is invalid.',
            );
        }

        return $value;
    }

    private function positiveInt(mixed $value): int
    {
        if (! is_int($value) || $value < 1) {
            throw ClassifierParserException::fatal(
                'invalid_rar_command_configuration',
                'The trusted RAR command configuration is invalid.',
            );
        }

        return $value;
    }

    private function nonNegativeInt(mixed $value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/', $value) === 1) {
            return (int) $value;
        }

        throw ClassifierParserException::fatal(
            'invalid_rar_listing',
            'The trusted RAR inspector returned invalid entry sizes.',
        );
    }

    private function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }

    /** @param array<string, mixed> $rawEntry */
    private function assertIntegrityTestPassed(array $rawEntry): void
    {
        if (! array_key_exists('lsarTestResult', $rawEntry) || ! is_string($rawEntry['lsarTestResult'])) {
            throw ClassifierParserException::fatal(
                'invalid_rar_listing',
                'The trusted RAR inspector did not return an integrity test result for an entry.',
            );
        }

        $testResult = $rawEntry['lsarTestResult'];

        if (! in_array($testResult, self::LSAR_TEST_RESULTS, true)) {
            throw ClassifierParserException::fatal(
                'invalid_rar_listing',
                'The trusted RAR inspector returned an unknown integrity test result.',
            );
        }

        if ($testResult !== 'ok') {
            throw ClassifierParserException::fatal(
                'corrupted_rar_entry',
                'The classifier RAR contains an entry that did not pass integrity testing.',
                ['lsar_test_result' => $testResult],
            );
        }
    }

    private function crc32(mixed $value): ?string
    {
        if (is_int($value) && $value >= 0) {
            return sprintf('%08x', $value);
        }

        if (is_string($value) && preg_match('/^[0-9]+$/', $value) === 1) {
            return sprintf('%08x', (int) $value);
        }

        return null;
    }
}
