<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Application\Contracts\ClassifierHttpTransport;
use App\Domain\PriceIndices\Application\Data\ClassifierHttpResponse;
use App\Domain\PriceIndices\Application\Data\TrustedClassifierDescriptor;
use App\Domain\PriceIndices\Application\Services\AcquireTrustedClassifierArtifact;
use App\Domain\PriceIndices\Application\Services\TrustedClassifierDescriptorRegistry;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierImport;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierNode;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierSourceFile;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierVersion;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierAcquisitionException;
use App\Domain\PriceIndices\Infrastructure\Persistence\ClassifierSourceFileRepository;
use App\Domain\PriceIndices\Infrastructure\Storage\ClassifierArtifactStorage;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

class ClassifierAcquisitionGateTwoTest extends TestCase
{
    use DatabaseTransactions;

    private string $diskRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->diskRoot = storage_path('framework/testing/disks/classifier-acquisition-'.Str::uuid());
        config()->set('filesystems.disks.price_indices_classifier_artifacts', [
            'driver' => 'local',
            'root' => $this->diskRoot,
            'serve' => false,
            'throw' => true,
            'report' => false,
        ]);
        Storage::forgetDisk('price_indices_classifier_artifacts');
    }

    protected function tearDown(): void
    {
        Storage::forgetDisk('price_indices_classifier_artifacts');

        if (isset($this->diskRoot) && File::isDirectory($this->diskRoot)) {
            File::deleteDirectory($this->diskRoot);
        }

        parent::tearDown();
    }

    public function test_valid_zip_is_streamed_through_same_host_redirect_and_stored_with_sanitized_metadata(): void
    {
        $bytes = $this->zipBytes('canonical-okpd2');
        $transport = new FakeClassifierHttpTransport([
            $this->response(302, '', ['Location' => ['/storage/final/OKPD2.zip']]),
            $this->response(200, $bytes, [
                'Content-Type' => ['application/zip; charset=binary'],
                'Content-Length' => [(string) strlen($bytes)],
                'ETag' => ['"official-etag"'],
                'Last-Modified' => ['Sun, 23 Aug 2026 10:20:30 GMT'],
            ]),
        ]);
        $this->app->instance(ClassifierHttpTransport::class, $transport);

        $result = app(AcquireTrustedClassifierArtifact::class)->acquire('okpd2');
        $expectedHash = hash('sha256', $bytes);

        $this->assertFalse($result->reused);
        $this->assertSame('okpd2', $result->classifier->code);
        $this->assertSame('https://rosstat.gov.ru/storage/final/OKPD2.zip', $result->resolvedUrl);
        $this->assertSame([
            'https://rosstat.gov.ru/storage/mediabank/OKPD2.zip',
            'https://rosstat.gov.ru/storage/final/OKPD2.zip',
        ], $transport->requestedUrls);
        $this->assertSame($expectedHash, $result->sourceFile->sha256);
        $this->assertSame(strlen($bytes), $result->sourceFile->size_bytes);
        $this->assertSame('application/zip', $result->sourceFile->mime_type);
        $this->assertSame('"official-etag"', $result->sourceFile->etag);
        $this->assertSame('2026-08-23 10:20:30', $result->sourceFile->last_modified_at?->format('Y-m-d H:i:s'));
        $this->assertNull($result->sourceFile->declared_version_label);
        $this->assertSame("classifiers/okpd2/artifacts/{$expectedHash}.zip", $result->sourceFile->storage_path);
        $this->assertSame($bytes, Storage::disk('price_indices_classifier_artifacts')->get($result->sourceFile->storage_path));
        $this->assertSame([], Storage::disk('price_indices_classifier_artifacts')->allFiles('.tmp'));
        $this->assertPrivateDiskConfiguration();
        $this->assertNoDownstreamLifecycle();
    }

    #[DataProvider('invalidDownloadProvider')]
    public function test_invalid_download_is_rejected_and_temporary_file_is_cleaned(
        string $expectedCode,
        string $body,
        array $headers,
        ?int $maxSize = null,
    ): void {
        if ($maxSize !== null) {
            config()->set('price_indices.classifier_acquisition.max_size_bytes', $maxSize);
        }

        $this->app->instance(ClassifierHttpTransport::class, new FakeClassifierHttpTransport([
            $this->response(200, $body, $headers),
        ]));

        try {
            app(AcquireTrustedClassifierArtifact::class)->acquire('okpd2');
            $this->fail('Invalid download should have been rejected.');
        } catch (ClassifierAcquisitionException $exception) {
            $this->assertSame($expectedCode, $exception->errorCode);
        }

        $this->assertSame([], Storage::disk('price_indices_classifier_artifacts')->allFiles());
        $this->assertSame(0, StatisticalClassifierSourceFile::query()->count());
        $this->assertNoDownstreamLifecycle();
    }

    /** @return array<string, array{string, string, array<string, list<string>>, ?int}> */
    public static function invalidDownloadProvider(): array
    {
        return [
            'empty' => ['empty_artifact', '', ['Content-Type' => ['application/zip']], null],
            'oversized content length' => [
                'artifact_too_large',
                "PK\x03\x04oversized",
                ['Content-Type' => ['application/zip'], 'Content-Length' => ['15']],
                8,
            ],
            'oversized streamed body' => [
                'artifact_too_large',
                "PK\x03\x04oversized",
                ['Content-Type' => ['application/zip']],
                8,
            ],
            'wrong mime' => [
                'invalid_mime_type',
                "PK\x03\x04payload",
                ['Content-Type' => ['text/html']],
                null,
            ],
            'wrong magic' => [
                'invalid_zip_signature',
                'not-a-zip',
                ['Content-Type' => ['application/octet-stream']],
                null,
            ],
            'partial content length' => [
                'partial_download',
                "PK\x03\x04short",
                ['Content-Type' => ['application/zip'], 'Content-Length' => ['999']],
                null,
            ],
            'invalid last modified' => [
                'invalid_response_metadata',
                "PK\x03\x04payload",
                ['Content-Type' => ['application/zip'], 'Last-Modified' => ['not-a-date']],
                null,
            ],
        ];
    }

    public function test_interrupted_response_stream_is_controlled_and_cleaned(): void
    {
        $calls = 0;
        $stream = new PumpStream(function () use (&$calls): string {
            if ($calls++ === 0) {
                return "PK\x03\x04partial";
            }

            throw new \RuntimeException('simulated network interruption');
        });
        $this->app->instance(ClassifierHttpTransport::class, new FakeClassifierHttpTransport([
            new ClassifierHttpResponse(200, ['Content-Type' => ['application/zip']], $stream),
        ]));

        try {
            app(AcquireTrustedClassifierArtifact::class)->acquire('okpd2');
            $this->fail('Interrupted stream should have failed.');
        } catch (ClassifierAcquisitionException $exception) {
            $this->assertSame('transport_failure', $exception->errorCode);
        }

        $this->assertSame([], Storage::disk('price_indices_classifier_artifacts')->allFiles());
        $this->assertSame(0, StatisticalClassifierSourceFile::query()->count());
    }

    #[DataProvider('transportFailureProvider')]
    public function test_network_and_timeout_failures_are_controlled(string $message): void
    {
        $this->app->instance(ClassifierHttpTransport::class, new FakeClassifierHttpTransport([
            new ClassifierAcquisitionException('transport_failure', $message),
        ]));

        $this->expectExceptionObject(new ClassifierAcquisitionException('transport_failure', $message));

        app(AcquireTrustedClassifierArtifact::class)->acquire('okpd2');
    }

    /** @return array<string, array{string}> */
    public static function transportFailureProvider(): array
    {
        return [
            'network failure' => ['simulated network failure'],
            'timeout' => ['simulated timeout'],
        ];
    }

    public function test_same_bytes_are_reused_across_same_or_changed_download_url(): void
    {
        $bytes = $this->zipBytes('same-content');
        $transport = new FakeClassifierHttpTransport([
            $this->response(200, $bytes),
            $this->response(200, $bytes),
            $this->response(200, $bytes),
        ]);
        $this->app->instance(ClassifierHttpTransport::class, $transport);
        $service = app(AcquireTrustedClassifierArtifact::class);

        $first = $service->acquire('okpd2');
        $sameUrl = $service->acquire('okpd2');
        config()->set(
            'price_indices.classifier_acquisition.descriptors.okpd2.download_url',
            'https://rosstat.gov.ru/storage/mediabank/renamed.zip',
        );
        $changedUrl = $service->acquire('okpd2');

        $this->assertFalse($first->reused);
        $this->assertTrue($sameUrl->reused);
        $this->assertTrue($changedUrl->reused);
        $this->assertTrue($first->sourceFile->is($sameUrl->sourceFile));
        $this->assertTrue($first->sourceFile->is($changedUrl->sourceFile));
        $this->assertSame(1, StatisticalClassifierSourceFile::query()->count());
        $this->assertSame(1, count(Storage::disk('price_indices_classifier_artifacts')->allFiles('classifiers')));
    }

    public function test_same_url_with_changed_bytes_creates_a_new_immutable_artifact(): void
    {
        $firstBytes = $this->zipBytes('first');
        $secondBytes = $this->zipBytes('second');
        $this->app->instance(ClassifierHttpTransport::class, new FakeClassifierHttpTransport([
            $this->response(200, $firstBytes),
            $this->response(200, $secondBytes),
        ]));
        $service = app(AcquireTrustedClassifierArtifact::class);

        $first = $service->acquire('okpd2');
        $second = $service->acquire('okpd2');

        $this->assertFalse($first->reused);
        $this->assertFalse($second->reused);
        $this->assertNotSame($first->sourceFile->sha256, $second->sourceFile->sha256);
        $this->assertSame(2, StatisticalClassifierSourceFile::query()->count());
        $this->assertSame($firstBytes, Storage::disk('price_indices_classifier_artifacts')->get($first->sourceFile->storage_path));
        $this->assertSame($secondBytes, Storage::disk('price_indices_classifier_artifacts')->get($second->sourceFile->storage_path));
    }

    public function test_existing_valid_orphan_artifact_is_reused_without_overwrite(): void
    {
        $bytes = $this->zipBytes('orphan');
        $descriptor = app(TrustedClassifierDescriptorRegistry::class)->get('okpd2');
        $path = app(ClassifierArtifactStorage::class)->finalPath($descriptor, hash('sha256', $bytes));
        Storage::disk($descriptor->storageDisk)->put($path, $bytes);
        $beforeModified = File::lastModified(Storage::disk($descriptor->storageDisk)->path($path));
        $this->app->instance(ClassifierHttpTransport::class, new FakeClassifierHttpTransport([
            $this->response(200, $bytes),
        ]));

        $result = app(AcquireTrustedClassifierArtifact::class)->acquire('okpd2');

        $this->assertFalse($result->reused);
        $this->assertSame($bytes, Storage::disk($descriptor->storageDisk)->get($path));
        $this->assertSame($beforeModified, File::lastModified(Storage::disk($descriptor->storageDisk)->path($path)));
        $this->assertSame(1, StatisticalClassifierSourceFile::query()->count());
    }

    public function test_corrupted_existing_destination_without_db_row_fails_closed_without_clobber(): void
    {
        $bytes = $this->zipBytes('expected');
        $descriptor = app(TrustedClassifierDescriptorRegistry::class)->get('okpd2');
        $path = app(ClassifierArtifactStorage::class)->finalPath($descriptor, hash('sha256', $bytes));
        Storage::disk($descriptor->storageDisk)->put($path, 'corrupt');
        $this->app->instance(ClassifierHttpTransport::class, new FakeClassifierHttpTransport([
            $this->response(200, $bytes),
        ]));

        try {
            app(AcquireTrustedClassifierArtifact::class)->acquire('okpd2');
            $this->fail('Corrupted destination should have failed closed.');
        } catch (ClassifierAcquisitionException $exception) {
            $this->assertSame('storage_integrity_failure', $exception->errorCode);
        }

        $this->assertSame('corrupt', Storage::disk($descriptor->storageDisk)->get($path));
        $this->assertSame(0, StatisticalClassifierSourceFile::query()->count());
        $this->assertSame([], Storage::disk($descriptor->storageDisk)->allFiles('.tmp'));
    }

    public function test_missing_or_corrupted_artifact_for_existing_db_row_is_not_repaired_or_reused(): void
    {
        $bytes = $this->zipBytes('stored');
        $transport = new FakeClassifierHttpTransport([
            $this->response(200, $bytes),
            $this->response(200, $bytes),
        ]);
        $this->app->instance(ClassifierHttpTransport::class, $transport);
        $service = app(AcquireTrustedClassifierArtifact::class);
        $first = $service->acquire('okpd2');
        Storage::disk($first->sourceFile->storage_disk)->delete($first->sourceFile->storage_path);

        try {
            $service->acquire('okpd2');
            $this->fail('Missing artifact should not be repaired implicitly.');
        } catch (ClassifierAcquisitionException $exception) {
            $this->assertSame('storage_integrity_failure', $exception->errorCode);
        }

        $this->assertFalse(Storage::disk($first->sourceFile->storage_disk)->exists($first->sourceFile->storage_path));
        $this->assertSame(1, StatisticalClassifierSourceFile::query()->count());
    }

    public function test_expected_database_duplicate_race_recovers_the_winning_entity(): void
    {
        $bytes = $this->zipBytes('race');
        $this->app->instance(ClassifierHttpTransport::class, new FakeClassifierHttpTransport([
            $this->response(200, $bytes),
        ]));
        $this->app->instance(ClassifierSourceFileRepository::class, new class extends ClassifierSourceFileRepository
        {
            private bool $firstLookup = true;

            public function findByClassifierAndHash(int $classifierId, string $sha256): ?StatisticalClassifierSourceFile
            {
                if ($this->firstLookup) {
                    $this->firstLookup = false;

                    return null;
                }

                return parent::findByClassifierAndHash($classifierId, $sha256);
            }

            public function create(array $attributes): StatisticalClassifierSourceFile
            {
                parent::create($attributes);

                return parent::create($attributes);
            }
        });

        $result = app(AcquireTrustedClassifierArtifact::class)->acquire('okpd2');

        $this->assertTrue($result->reused);
        $this->assertSame(1, StatisticalClassifierSourceFile::query()->count());
        $this->assertSame($bytes, Storage::disk($result->sourceFile->storage_disk)->get($result->sourceFile->storage_path));
    }

    private function response(int $status, string $body, array $headers = []): ClassifierHttpResponse
    {
        $headers += ['Content-Type' => ['application/zip']];

        return new ClassifierHttpResponse($status, $headers, Utils::streamFor($body));
    }

    private function zipBytes(string $payload): string
    {
        return "PK\x03\x04".$payload;
    }

    private function assertPrivateDiskConfiguration(): void
    {
        $disk = config('filesystems.disks.price_indices_classifier_artifacts');

        $this->assertSame('local', $disk['driver']);
        $this->assertFalse($disk['serve']);
        $this->assertTrue($disk['throw']);
        $this->assertStringNotContainsString('public', str_replace('price-indices-classifiers', '', $disk['root']));
    }

    private function assertNoDownstreamLifecycle(): void
    {
        $this->assertSame(0, StatisticalClassifierImport::query()->count());
        $this->assertSame(0, StatisticalClassifierVersion::query()->count());
        $this->assertSame(0, StatisticalClassifierNode::query()->count());
        $this->assertSame(0, \DB::table('statistical_classifier_active_versions')->count());
    }
}

class FakeClassifierHttpTransport implements ClassifierHttpTransport
{
    /** @var list<ClassifierHttpResponse|Throwable> */
    private array $responses;

    /** @var list<string> */
    public array $requestedUrls = [];

    /** @param list<ClassifierHttpResponse|Throwable> $responses */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function get(string $url, TrustedClassifierDescriptor $descriptor): ClassifierHttpResponse
    {
        $this->requestedUrls[] = $url;
        $next = array_shift($this->responses);

        if ($next instanceof Throwable) {
            throw $next;
        }

        if (! $next instanceof ClassifierHttpResponse) {
            throw new \LogicException('No fake classifier HTTP response remains.');
        }

        return $next;
    }
}
