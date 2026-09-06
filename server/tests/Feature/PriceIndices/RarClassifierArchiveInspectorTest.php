<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Application\Data\ZipSafetyLimits;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierParserException;
use App\Domain\PriceIndices\Infrastructure\Parsing\ClassifierArchiveCommandResult;
use App\Domain\PriceIndices\Infrastructure\Parsing\ClassifierArchiveCommandRunner;
use App\Domain\PriceIndices\Infrastructure\Parsing\ClassifierArchiveEntry;
use App\Domain\PriceIndices\Infrastructure\Parsing\InspectedRarArchive;
use App\Domain\PriceIndices\Infrastructure\Parsing\RarClassifierArchiveInspector;
use App\Domain\PriceIndices\Infrastructure\Parsing\ZipEntryNamePolicy;
use Tests\TestCase;

class RarClassifierArchiveInspectorTest extends TestCase
{
    private string $archivePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->archivePath = tempnam(sys_get_temp_dir(), 'prism-rar-test-');
        file_put_contents($this->archivePath, "Rar!\x1a\x07\x01\x00test");
    }

    protected function tearDown(): void
    {
        if (is_file($this->archivePath)) {
            @unlink($this->archivePath);
        }

        parent::tearDown();
    }

    public function test_rar_listing_is_validated_and_allowlisted_entries_are_materialized(): void
    {
        $payloads = [
            'OKPD2 01-35.docx' => 'part-one',
            'OKPD2 36-99.docx' => 'part-two',
        ];
        $runner = new FakeRarCommandRunner($this->listing($payloads), $payloads);
        $archive = $this->inspector($runner)->open(
            $this->archivePath,
            $this->limits(),
            $this->configuration(),
        );

        $this->assertSame('rar', $archive->type());
        $this->assertSame(array_keys($payloads), $archive->fileNames());
        $temporary = $archive->materialize('OKPD2 36-99.docx', 'prism-rar-materialized-');

        try {
            $this->assertSame($payloads['OKPD2 36-99.docx'], file_get_contents($temporary->path));
        } finally {
            $temporary->close();
            $archive->close();
        }

        $this->assertSame(
            ['lsar', '-test', '-json', '-no-recursion', $this->archivePath],
            $runner->commands[0],
        );
        $this->assertSame(2, count($runner->commands));
    }

    public function test_production_rar5_tested_json_passes_and_preserves_metadata(): void
    {
        $listing = $this->productionListing();
        $this->assertSame(['ok', 'ok'], array_column($listing['lsarContents'], 'lsarTestResult'));
        $this->assertIsArray($listing['lsarContents'][0]['RAR5InputParts']);
        $this->assertIsArray($listing['lsarContents'][1]['RAR5InputParts']);

        $runner = new FakeRarCommandRunner($listing, []);
        $archive = $this->inspector($runner)->open(
            $this->archivePath,
            $this->productionLimits(),
            $this->configuration(),
        );

        $this->assertSame(
            ['OKPD2 01-35.docx', 'OKPD2 36-99.docx'],
            $archive->fileNames(),
        );
        $this->assertSame([688229, 410397], array_map(
            static fn (ClassifierArchiveEntry $entry): int => $entry->uncompressedBytes,
            $archive->entries,
        ));
        $this->assertSame([660698, 395008], array_map(
            static fn (ClassifierArchiveEntry $entry): int => $entry->compressedBytes,
            $archive->entries,
        ));
        $this->assertSame(['34724f0b', '1269d851'], array_map(
            static fn (ClassifierArchiveEntry $entry): ?string => $entry->crc32,
            $archive->entries,
        ));
        $this->assertSame(
            ['lsar', '-test', '-json', '-no-recursion', $this->archivePath],
            $runner->commands[0],
        );
    }

    public function test_unar_containing_directory_behavior_is_disabled_for_both_production_entries(): void
    {
        $payloads = [
            'OKPD2 01-35.docx' => 'part-one',
            'OKPD2 36-99.docx' => 'part-two',
        ];
        $runner = new FakeRarCommandRunner($this->listing($payloads), $payloads);
        $archive = $this->open($runner);

        try {
            foreach ($payloads as $name => $payload) {
                $temporary = $archive->materialize($name, 'prism-rar-materialized-');

                try {
                    $this->assertSame($payload, file_get_contents($temporary->path));
                } finally {
                    $temporary->close();
                }
            }
        } finally {
            $archive->close();
        }

        $this->assertCount(3, $runner->commands);
        $this->assertSame(
            ['unar', '-f', '-no-directory', '-o', $runner->commands[1][4], $this->archivePath, 'OKPD2 01-35.docx'],
            $runner->commands[1],
        );
        $this->assertSame(
            ['unar', '-f', '-no-directory', '-o', $runner->commands[2][4], $this->archivePath, 'OKPD2 36-99.docx'],
            $runner->commands[2],
        );
    }

    public function test_explicit_failed_lsar_test_result_rejects_entry(): void
    {
        $listing = $this->listing(['OKPD2 01-35.docx' => 'one']);
        $listing['lsarContents'][0]['lsarTestResult'] = 'wrong_checksum';
        $runner = new FakeRarCommandRunner($listing, []);

        $this->assertOpenError('corrupted_rar_entry', $runner);
    }

    public function test_missing_or_unknown_lsar_test_result_is_malformed_listing(): void
    {
        foreach ([null, 'unexpected_result'] as $testResult) {
            $listing = $this->listing(['OKPD2 01-35.docx' => 'one']);

            if ($testResult === null) {
                unset($listing['lsarContents'][0]['lsarTestResult']);
            } else {
                $listing['lsarContents'][0]['lsarTestResult'] = $testResult;
            }

            $this->assertOpenError('invalid_rar_listing', new FakeRarCommandRunner($listing, []));
        }
    }

    public function test_lsar_non_zero_exit_code_rejects_archive(): void
    {
        $runner = new FakeRarCommandRunner(
            $this->listing(['OKPD2 01-35.docx' => 'one']),
            [],
            inspectionExitCode: 1,
        );

        $this->assertOpenError('unreadable_rar', $runner);
    }

    public function test_malformed_lsar_json_rejects_archive(): void
    {
        $runner = new FakeRarCommandRunner(
            $this->listing(['OKPD2 01-35.docx' => 'one']),
            [],
            inspectionStdout: '{malformed',
        );

        $this->assertOpenError('invalid_rar_listing', $runner);
    }

    public function test_crc_mismatch_after_extraction_is_rejected_independently(): void
    {
        $listing = $this->listing(['OKPD2 01-35.docx' => 'expected']);
        $listing['lsarContents'][0]['RAR5CRC32'] = hexdec('00000000');
        $runner = new FakeRarCommandRunner($listing, ['OKPD2 01-35.docx' => 'changed!']);
        $archive = $this->open($runner);

        $this->assertMaterializeError('rar_entry_integrity_failure', $archive, 'OKPD2 01-35.docx');
    }

    public function test_size_mismatch_after_extraction_is_rejected_independently(): void
    {
        $listing = $this->listing(['OKPD2 01-35.docx' => 'expected']);
        $listing['lsarContents'][0]['XADFileSize'] = 19;
        $runner = new FakeRarCommandRunner($listing, ['OKPD2 01-35.docx' => 'expected']);
        $archive = $this->open($runner);

        $this->assertMaterializeError('rar_entry_integrity_failure', $archive, 'OKPD2 01-35.docx');
    }

    public function test_rar_rejects_duplicate_encrypted_oversized_and_unsafe_entries(): void
    {
        $cases = [
            'duplicate' => [
                [
                    $this->entry('OKPD2 01-35.docx', 'one'),
                    $this->entry('okpd2 01-35.docx', 'two'),
                ],
                'duplicate_rar_entry',
            ],
            'encrypted' => [
                [$this->entry('OKPD2 01-35.docx', 'one', ['XADIsEncrypted' => 1])],
                'encrypted_rar_entry',
            ],
            'symbolic link' => [
                [$this->entry('OKPD2 01-35.docx', 'one', ['XADIsSymbolicLink' => 1])],
                'special_rar_entry',
            ],
            'link' => [
                [$this->entry('OKPD2 01-35.docx', 'one', ['XADIsLink' => 1])],
                'special_rar_entry',
            ],
            'hard link' => [
                [$this->entry('OKPD2 01-35.docx', 'one', ['XADIsHardLink' => 1])],
                'special_rar_entry',
            ],
            'special file' => [
                [$this->entry('OKPD2 01-35.docx', 'one', ['XADIsSpecialFile' => 1])],
                'special_rar_entry',
            ],
            'oversized' => [
                [$this->entry('OKPD2 01-35.docx', 'one', ['XADFileSize' => 100])],
                'rar_entry_size_limit',
            ],
            'unsafe path' => [
                [$this->entry('../OKPD2 01-35.docx', 'one')],
                'unsafe_zip_entry_path',
            ],
        ];

        foreach ($cases as [$entries, $expectedCode]) {
            $runner = new FakeRarCommandRunner(['lsarContents' => $entries], []);

            try {
                $this->inspector($runner)->open(
                    $this->archivePath,
                    $this->limits(),
                    $this->configuration(),
                );
                $this->fail("RAR case [{$expectedCode}] was accepted.");
            } catch (ClassifierParserException $exception) {
                $this->assertSame($expectedCode, $exception->errorCode);
            }
        }
    }

    public function test_rar_safety_limits_remain_enforced(): void
    {
        $this->assertOpenError(
            'rar_total_size_limit',
            new FakeRarCommandRunner([
                'lsarContents' => [
                    $this->entry('OKPD2 01-35.docx', str_repeat('a', 20)),
                    $this->entry('OKPD2 36-99.docx', str_repeat('b', 20)),
                ],
            ], []),
            limits: new ZipSafetyLimits(8, 20, 30, 200, 40),
        );

        $this->assertOpenError(
            'rar_total_compressed_size_limit',
            new FakeRarCommandRunner([
                'lsarContents' => [
                    $this->entry('OKPD2 01-35.docx', 'a', ['XADCompressedSize' => 101]),
                    $this->entry('OKPD2 36-99.docx', 'b', ['XADCompressedSize' => 101]),
                ],
            ], []),
        );

        $this->assertOpenError(
            'rar_compression_ratio_limit',
            new FakeRarCommandRunner([
                'lsarContents' => [
                    $this->entry('OKPD2 01-35.docx', str_repeat('a', 20), ['XADCompressedSize' => 1]),
                ],
            ], []),
            limits: new ZipSafetyLimits(8, 20, 40, 5, 200),
        );
    }

    private function inspector(FakeRarCommandRunner $runner): RarClassifierArchiveInspector
    {
        return new RarClassifierArchiveInspector($runner, new ZipEntryNamePolicy);
    }

    private function limits(): ZipSafetyLimits
    {
        return new ZipSafetyLimits(8, 20, 40, 200, 40);
    }

    private function productionLimits(): ZipSafetyLimits
    {
        return new ZipSafetyLimits(8, 700_000, 1_200_000, 200, 2_000_000);
    }

    private function open(FakeRarCommandRunner $runner): InspectedRarArchive
    {
        return $this->inspector($runner)->open(
            $this->archivePath,
            $this->limits(),
            $this->configuration(),
        );
    }

    private function assertOpenError(string $expectedCode, FakeRarCommandRunner $runner, ?ZipSafetyLimits $limits = null): void
    {
        try {
            $this->inspector($runner)->open(
                $this->archivePath,
                $limits ?? $this->limits(),
                $this->configuration(),
            );
            $this->fail("RAR error [{$expectedCode}] was not raised.");
        } catch (ClassifierParserException $exception) {
            $this->assertSame($expectedCode, $exception->errorCode);
        }
    }

    private function assertMaterializeError(string $expectedCode, InspectedRarArchive $archive, string $name): void
    {
        try {
            $archive->materialize($name, 'prism-rar-materialized-');
            $this->fail("RAR materialization error [{$expectedCode}] was not raised.");
        } catch (ClassifierParserException $exception) {
            $this->assertSame($expectedCode, $exception->errorCode);
        } finally {
            $archive->close();
        }
    }

    /** @return array<string, mixed> */
    private function productionListing(): array
    {
        $json = file_get_contents(__DIR__.'/Fixtures/okpd2-rar5-lsar-tested.json');

        $this->assertIsString($json);

        $listing = json_decode($json, true, 32, JSON_THROW_ON_ERROR);

        $this->assertIsArray($listing);

        return $listing;
    }

    /** @return array<string, mixed> */
    private function configuration(): array
    {
        return [
            'inspector_binary' => 'lsar',
            'extractor_binary' => 'unar',
            'max_entries' => 8,
            'max_listing_bytes' => 2_097_152,
            'command_timeout_seconds' => 5,
        ];
    }

    /** @param array<string, string> $payloads */
    private function listing(array $payloads): array
    {
        return [
            'lsarContents' => array_map(
                fn (string $payload, string $name): array => $this->entry($name, $payload),
                $payloads,
                array_keys($payloads),
            ),
        ];
    }

    /** @param array<string, int|string> $overrides */
    private function entry(string $name, string $payload, array $overrides = []): array
    {
        return array_merge([
            'XADFileName' => $name,
            'XADFileSize' => strlen($payload),
            'XADCompressedSize' => strlen($payload),
            'XADIsDirectory' => 0,
            'XADIsEncrypted' => 0,
            'XADIsSymbolicLink' => 0,
            'XADIsSpecialFile' => 0,
            'RAR5CRC32' => hexdec(hash('crc32b', $payload)),
            'lsarTestResult' => 'ok',
        ], $overrides);
    }
}

final class FakeRarCommandRunner implements ClassifierArchiveCommandRunner
{
    /** @var list<list<string>> */
    public array $commands = [];

    /** @param array<string, mixed> $listing @param array<string, string> $payloads */
    public function __construct(
        private readonly array $listing,
        private readonly array $payloads,
        private readonly int $inspectionExitCode = 0,
        private readonly ?string $inspectionStdout = null,
    ) {}

    public function run(array $command, int $maxOutputBytes, int $timeoutSeconds): ClassifierArchiveCommandResult
    {
        $this->commands[] = $command;

        if (in_array('-json', $command, true)) {
            return new ClassifierArchiveCommandResult(
                $this->inspectionExitCode,
                $this->inspectionStdout ?? json_encode($this->listing, JSON_THROW_ON_ERROR),
                '',
            );
        }

        $outputDirectoryOptionIndex = array_search('-o', $command, true);
        $outputDirectory = is_int($outputDirectoryOptionIndex)
            ? (string) ($command[$outputDirectoryOptionIndex + 1] ?? '')
            : '';
        $archivePath = is_int($outputDirectoryOptionIndex)
            ? (string) ($command[$outputDirectoryOptionIndex + 2] ?? '')
            : '';
        $name = (string) end($command);

        if (! isset($this->payloads[$name])) {
            return new ClassifierArchiveCommandResult(1, '', 'missing');
        }

        $targetDirectory = $outputDirectory;

        if (! in_array('-no-directory', $command, true)) {
            $targetDirectory .= DIRECTORY_SEPARATOR.pathinfo($archivePath, PATHINFO_FILENAME);
        }

        $targetPath = $targetDirectory.DIRECTORY_SEPARATOR.str_replace(
            ['/', '\\'],
            DIRECTORY_SEPARATOR,
            $name,
        );
        $parentDirectory = dirname($targetPath);

        if (! is_dir($parentDirectory) && ! @mkdir($parentDirectory, 0700, true)) {
            return new ClassifierArchiveCommandResult(1, '', 'unable to create output directory');
        }

        file_put_contents($targetPath, $this->payloads[$name]);

        return new ClassifierArchiveCommandResult(0, '', '');
    }
}
