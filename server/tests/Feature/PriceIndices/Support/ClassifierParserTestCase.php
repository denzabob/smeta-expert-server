<?php

namespace Tests\Feature\PriceIndices\Support;

use App\Domain\PriceIndices\Application\Data\ClassifierExpectedProfile;
use App\Domain\PriceIndices\Application\Services\ParseOkpd2ClassifierArtifact;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierSourceFile;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierParserException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class ClassifierParserTestCase extends TestCase
{
    use BuildsSyntheticOkpd2Artifact;

    private string $classifierParserDiskRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->classifierParserDiskRoot = storage_path('framework/testing/disks/classifier-parser-'.Str::uuid());
        config()->set('filesystems.disks.price_indices_classifier_artifacts', [
            'driver' => 'local',
            'root' => $this->classifierParserDiskRoot,
            'serve' => false,
            'throw' => true,
            'report' => false,
        ]);
        Storage::forgetDisk('price_indices_classifier_artifacts');
    }

    protected function tearDown(): void
    {
        Storage::forgetDisk('price_indices_classifier_artifacts');

        if (isset($this->classifierParserDiskRoot) && File::isDirectory($this->classifierParserDiskRoot)) {
            File::deleteDirectory($this->classifierParserDiskRoot);
        }

        $this->forgetSyntheticOkpd2Artifacts();
        parent::tearDown();
    }

    protected function parser(): ParseOkpd2ClassifierArtifact
    {
        return app(ParseOkpd2ClassifierArtifact::class);
    }

    protected function storeSyntheticArtifact(string $sourcePath): StatisticalClassifierSourceFile
    {
        $sha256 = hash_file('sha256', $sourcePath);
        $storagePath = "classifiers/okpd2/artifacts/{$sha256}.zip";
        $stream = fopen($sourcePath, 'rb');

        if ($stream === false) {
            $this->fail('Unable to open a synthetic classifier artifact.');
        }

        try {
            Storage::disk('price_indices_classifier_artifacts')->writeStream($storagePath, $stream);
        } finally {
            fclose($stream);
        }

        return new StatisticalClassifierSourceFile([
            'storage_disk' => 'price_indices_classifier_artifacts',
            'storage_path' => $storagePath,
            'sha256' => $sha256,
            'size_bytes' => filesize($sourcePath),
            'original_filename' => 'OKPD2.zip',
        ]);
    }

    protected function syntheticExpectedProfile(
        int $digitalNodes = 7,
        int $totalNodes = 28,
        ?array $levelCounts = null,
    ): ClassifierExpectedProfile {
        return new ClassifierExpectedProfile(
            requiredSections: range('A', 'U'),
            minimumDigitalNodes: 1,
            exactSectionsCount: 21,
            exactDigitalNodesCount: $digitalNodes,
            exactTotalNodesCount: $totalNodes,
            exactLevelCounts: $levelCounts ?? [
                'section' => 21,
                'class' => 1,
                'subclass' => 1,
                'group' => 1,
                'subgroup' => 1,
                'type' => 1,
                'category' => 1,
                'subcategory' => 1,
            ],
        );
    }

    protected function assertParserError(string $expectedCode, callable $callback): void
    {
        try {
            $callback();
            $this->fail("Parser error [{$expectedCode}] was not raised.");
        } catch (ClassifierParserException $exception) {
            $this->assertSame($expectedCode, $exception->errorCode);
            $this->assertSame($expectedCode, $exception->validationSummary->fatalErrors[0]->code);
        }
    }
}
