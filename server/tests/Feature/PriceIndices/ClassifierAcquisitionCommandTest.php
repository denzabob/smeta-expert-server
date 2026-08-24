<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Application\Contracts\ClassifierHttpTransport;
use App\Domain\PriceIndices\Application\Data\ClassifierHttpResponse;
use App\Domain\PriceIndices\Application\Data\TrustedClassifierDescriptor;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierImport;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierNode;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierSourceFile;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierVersion;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Tests\TestCase;

class ClassifierAcquisitionCommandTest extends TestCase
{
    use DatabaseTransactions;

    private string $diskRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->diskRoot = storage_path('framework/testing/disks/classifier-command-'.Str::uuid());
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

    public function test_command_accepts_only_a_trusted_classifier_code_argument(): void
    {
        $command = Artisan::all()['price-indices:classifier:acquire'];
        $arguments = $command->getDefinition()->getArguments();

        $this->assertSame(['classifier'], array_keys($arguments));
        $this->assertFalse($command->getDefinition()->hasArgument('url'));
    }

    public function test_unknown_classifier_is_a_controlled_error_without_http_or_database_side_effects(): void
    {
        $transport = new CommandFakeClassifierTransport("PK\x03\x04unused");
        $this->app->instance(ClassifierHttpTransport::class, $transport);

        $exitCode = Artisan::call('price-indices:classifier:acquire', ['classifier' => 'https://example.com/file.zip']);
        $output = Artisan::output();

        $this->assertSame(SymfonyCommand::FAILURE, $exitCode);
        $this->assertStringContainsString('classifier_not_supported', $output);
        $this->assertSame([], $transport->requestedUrls);
        $this->assertDatabaseCount('statistical_classifiers', 0);
        $this->assertDatabaseCount('statistical_classifier_source_files', 0);
    }

    public function test_success_output_uses_public_identity_and_omits_private_or_numeric_identity(): void
    {
        $bytes = "PK\x03\x04command-success";
        $transport = new CommandFakeClassifierTransport($bytes);
        $this->app->instance(ClassifierHttpTransport::class, $transport);

        $exitCode = Artisan::call('price-indices:classifier:acquire', ['classifier' => 'okpd2']);
        $output = Artisan::output();
        $sourceFile = StatisticalClassifierSourceFile::query()->sole();

        $this->assertSame(SymfonyCommand::SUCCESS, $exitCode);
        $this->assertStringContainsString('classifier_public_id', $output);
        $this->assertStringContainsString($sourceFile->classifier->public_id, $output);
        $this->assertStringContainsString('source_artifact_public_id', $output);
        $this->assertStringContainsString($sourceFile->public_id, $output);
        $this->assertStringContainsString(hash('sha256', $bytes), $output);
        $this->assertStringContainsString('new', $output);
        $this->assertStringNotContainsString($sourceFile->storage_path, $output);
        $this->assertStringNotContainsString('storage_path', $output);
        $this->assertStringNotContainsString('classifier_id', $output);
        $this->assertSame(['https://rosstat.gov.ru/storage/mediabank/OKPD2.zip'], $transport->requestedUrls);
        $this->assertNoDownstreamLifecycle();
    }

    public function test_repeated_command_reports_reused_without_creating_downstream_rows(): void
    {
        $transport = new CommandFakeClassifierTransport("PK\x03\x04same-command-bytes");
        $this->app->instance(ClassifierHttpTransport::class, $transport);

        $this->assertSame(SymfonyCommand::SUCCESS, Artisan::call('price-indices:classifier:acquire', ['classifier' => 'okpd2']));
        $this->assertSame(SymfonyCommand::SUCCESS, Artisan::call('price-indices:classifier:acquire', ['classifier' => 'okpd2']));

        $this->assertStringContainsString('reused', Artisan::output());
        $this->assertDatabaseCount('statistical_classifier_source_files', 1);
        $this->assertNoDownstreamLifecycle();
    }

    public function test_production_transport_source_keeps_strict_tls_and_disables_automatic_redirects(): void
    {
        $source = File::get(app_path('Domain/PriceIndices/Infrastructure/Http/LaravelClassifierHttpTransport.php'));

        $this->assertStringContainsString("'verify' => true", $source);
        $this->assertStringContainsString("'allow_redirects' => false", $source);
        $this->assertStringNotContainsString("'verify' => false", $source);
        $this->assertStringNotContainsString('CURLOPT_SSL_VERIFYPEER', $source);
    }

    private function assertNoDownstreamLifecycle(): void
    {
        $this->assertSame(0, StatisticalClassifierImport::query()->count());
        $this->assertSame(0, StatisticalClassifierVersion::query()->count());
        $this->assertSame(0, StatisticalClassifierNode::query()->count());
        $this->assertSame(0, \DB::table('statistical_classifier_active_versions')->count());
    }
}

class CommandFakeClassifierTransport implements ClassifierHttpTransport
{
    /** @var list<string> */
    public array $requestedUrls = [];

    public function __construct(private readonly string $bytes) {}

    public function get(string $url, TrustedClassifierDescriptor $descriptor): ClassifierHttpResponse
    {
        $this->requestedUrls[] = $url;

        return new ClassifierHttpResponse(200, [
            'Content-Type' => ['application/zip'],
            'Content-Length' => [(string) strlen($this->bytes)],
            'ETag' => ['"command-etag"'],
            'Last-Modified' => ['Sun, 23 Aug 2026 10:20:30 GMT'],
        ], Utils::streamFor($this->bytes));
    }
}
