<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Application\Data\ZipSafetyLimits;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierParserException;
use App\Domain\PriceIndices\Infrastructure\Parsing\ClassifierArchiveCommandResult;
use App\Domain\PriceIndices\Infrastructure\Parsing\ClassifierArchiveCommandRunner;
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

        $this->assertSame(2, count($runner->commands));
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

    private function inspector(FakeRarCommandRunner $runner): RarClassifierArchiveInspector
    {
        return new RarClassifierArchiveInspector($runner, new ZipEntryNamePolicy);
    }

    private function limits(): ZipSafetyLimits
    {
        return new ZipSafetyLimits(8, 20, 40, 200, 40);
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
    /** @var list<string> */
    public array $commands = [];

    /** @param array<string, mixed> $listing @param array<string, string> $payloads */
    public function __construct(
        private readonly array $listing,
        private readonly array $payloads,
    ) {}

    public function run(array $command, int $maxOutputBytes, int $timeoutSeconds): ClassifierArchiveCommandResult
    {
        $this->commands[] = implode(' ', $command);

        if (in_array('-j', $command, true)) {
            return new ClassifierArchiveCommandResult(
                0,
                json_encode($this->listing, JSON_THROW_ON_ERROR),
                '',
            );
        }

        $outputDirectory = $command[3] ?? '';
        $name = (string) end($command);

        if (! isset($this->payloads[$name])) {
            return new ClassifierArchiveCommandResult(1, '', 'missing');
        }

        file_put_contents($outputDirectory.DIRECTORY_SEPARATOR.$name, $this->payloads[$name]);

        return new ClassifierArchiveCommandResult(0, '', '');
    }
}
