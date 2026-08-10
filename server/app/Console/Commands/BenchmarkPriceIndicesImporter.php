<?php

namespace App\Console\Commands;

use App\Domain\PriceIndices\Application\Services\BeginImportValidation;
use App\Domain\PriceIndices\Application\Services\CreateStatisticalImport;
use App\Domain\PriceIndices\Application\Services\MarkImportReadyForPublish;
use App\Domain\PriceIndices\Application\Services\StartStatisticalImport;
use App\Domain\PriceIndices\Application\Services\StatisticalImporterRegistry;
use App\Domain\PriceIndices\Application\Services\StatisticalImportPreviewCacheKey;
use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Enums\AcquisitionMethod;
use App\Domain\PriceIndices\Domain\Enums\SourceFileStatus;
use App\Domain\PriceIndices\Domain\Enums\StatisticalImportPreviewStatus;
use App\Domain\PriceIndices\Domain\Enums\ValidationStatus;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Domain\PriceIndices\Domain\Previews\StatisticalImportPreview;
use App\Domain\PriceIndices\Domain\SourceFiles\StatisticalSourceFile;
use App\Jobs\RunStatisticalImportPreviewJob;
use Database\Seeders\ProducerPriceIndicesDatasetSeeder;
use Database\Seeders\ProducerPriceIndicesReferenceSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class BenchmarkPriceIndicesImporter extends Command
{
    protected $signature = 'price-indices:benchmark-importer
        {path : Absolute or working-directory-relative XLSX path}
        {--chunks=1000,2000,5000 : Comma-separated row chunk sizes}
        {--preview-only : Run read-only preview once without benchmark imports}
        {--async-preview : Run the persistent preview job once and roll back its DB row}
        {--assert-real-workbook : Enforce the audited workbook hash/counts/anchors}';

    protected $description = 'Run opt-in Price Indices XLSX regression/benchmark on a non-production database';

    private const EXPECTED_HASH = 'f233b55e8c00ff378e4dfaf6d870d057f724dbe9ec0e3b49fca3ea8c27b0b691';

    public function handle(
        CreateStatisticalImport $create,
        StartStatisticalImport $start,
        StatisticalImporterRegistry $registry,
        StatisticalImportPreviewCacheKey $previewCacheKey,
        BeginImportValidation $beginValidation,
        MarkImportReadyForPublish $markReady,
    ): int {
        $database = DB::connection()->getDatabaseName();
        if (preg_match('/(?:test|benchmark)/i', $database) !== 1) {
            $this->error("Refusing to run importer benchmark on database [{$database}].");

            return self::FAILURE;
        }

        $inputPath = realpath((string) $this->argument('path'));
        if ($inputPath === false || ! is_file($inputPath)) {
            $this->error('Workbook path does not exist.');

            return self::FAILURE;
        }

        $hash = hash_file('sha256', $inputPath);
        if ($this->option('assert-real-workbook') && $hash !== self::EXPECTED_HASH) {
            $this->error("Unexpected workbook SHA-256: {$hash}");

            return self::FAILURE;
        }

        $storedPath = 'price-indices/benchmark/'.Str::uuid().'.xlsx';
        $stream = fopen($inputPath, 'rb');
        if ($stream === false || ! Storage::disk('local')->put($storedPath, $stream)) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            throw new RuntimeException('Unable to stage benchmark workbook.');
        }
        if (is_resource($stream)) {
            fclose($stream);
        }

        $this->line(json_encode([
            'connection' => DB::connection()->getName(),
            'database' => $database,
            'php_memory_limit' => ini_get('memory_limit'),
            'workbook_sha256' => $hash,
            'workbook_bytes' => filesize($inputPath),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        try {
            if ($this->option('async-preview')) {
                $metrics = $this->insideRollback(function (
                    StatisticalDataset $dataset,
                    StatisticalSourceFile $file,
                ) use ($previewCacheKey): array {
                    $importerCode = (string) config(
                        'price_indices.imports.importers.producer_price_indices_by_product.code'
                    );
                    $importerVersion = (string) config(
                        'price_indices.imports.importers.producer_price_indices_by_product.version'
                    );
                    $preview = StatisticalImportPreview::query()->create([
                        'dataset_id' => $dataset->id,
                        'source_file_id' => $file->id,
                        'importer_code' => $importerCode,
                        'importer_version' => $importerVersion,
                        'status' => StatisticalImportPreviewStatus::Pending,
                        'cache_key' => $previewCacheKey->forSourceFile(
                            $file,
                            $importerCode,
                            $importerVersion,
                        ),
                    ]);

                    app()->call([new RunStatisticalImportPreviewJob($preview->public_id), 'handle']);
                    $preview = $preview->refresh();
                    if ($preview->status !== StatisticalImportPreviewStatus::Ready) {
                        throw new RuntimeException(
                            "Async preview finished with status [{$preview->status->value}]."
                        );
                    }

                    return [
                        'status' => $preview->status->value,
                        'commodity_occurrences' => $preview->commodity_occurrences,
                        'unique_classifier_items' => $preview->unique_classifier_items,
                        'observation_candidates' => $preview->observation_candidates,
                        'numeric' => $preview->numeric_count,
                        'missing' => $preview->missing_count,
                        'footnoted' => $preview->footnoted_count,
                        'fatal_errors' => $preview->fatal_errors_count,
                        'result_json_bytes' => $preview->metadata_json['result_json_bytes'] ?? null,
                        'elapsed_seconds' => $preview->metadata_json['elapsed_seconds'] ?? null,
                        'peak_memory_bytes' => $preview->metadata_json['peak_memory_bytes'] ?? null,
                    ];
                }, $storedPath, $inputPath, $hash);

                if ($this->option('assert-real-workbook')) {
                    $this->assertRealAsyncPreview($metrics);
                }
                $this->line(json_encode($metrics, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }

            if ($this->option('preview-only')) {
                $preview = $this->insideRollback(function (StatisticalDataset $dataset, StatisticalSourceFile $file) use ($registry) {
                    return $registry->forSourceFile($file)->preview($file);
                }, $storedPath, $inputPath, $hash);
                $previewData = $preview->toArray();
                $this->line(json_encode([
                    'workbook' => $previewData['workbook'],
                    'supported_sheets' => array_map(
                        fn (array $sheet): array => array_intersect_key($sheet, array_flip(['name', 'year', 'topology', 'month_columns'])),
                        $previewData['structure']['supported_sheets'],
                    ),
                    'counts' => $previewData['counts'],
                    'control_samples' => array_values(array_slice(array_filter(
                        $previewData['sample_records'],
                        fn (array $sample): bool => $sample['item_code'] === '31.02.10.140'
                            || str_ends_with($sample['item_code'], '.АГ'),
                    ), 0, 10)),
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }

            $chunks = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $this->option('chunks'))))));
            if ($chunks === []) {
                throw new RuntimeException('At least one positive chunk size is required.');
            }

            // Warm filesystem/ZIP caches equally before measured full imports.
            $this->insideRollback(function (StatisticalDataset $dataset, StatisticalSourceFile $file) use ($registry): void {
                $registry->forSourceFile($file)->preview($file);
            }, $storedPath, $inputPath, $hash);

            foreach ($chunks as $chunkRows) {
                config(['price_indices.imports.chunk_rows' => $chunkRows]);
                if (function_exists('memory_reset_peak_usage')) {
                    memory_reset_peak_usage();
                }
                $metrics = $this->insideRollback(function (
                    StatisticalDataset $dataset,
                    StatisticalSourceFile $file,
                ) use ($create, $start, $registry, $beginValidation, $markReady): array {
                    $import = $create->execute($dataset, $file);
                    $import = $start->execute($import);
                    $result = $registry->forImport($import)->import($import);
                    $import = $beginValidation->execute($import->refresh());
                    $import = $markReady->execute($import);
                    $summary = $import->validation_summary_json;

                    return $result->toArray() + [
                        'status' => $import->status->value,
                        'ordinary_numeric' => $summary['ordinary_numeric'] ?? null,
                        'special_footnoted' => $summary['special_footnoted'] ?? null,
                        'anchors' => $this->controlAnchors($import),
                    ];
                }, $storedPath, $inputPath, $hash);

                if ($this->option('assert-real-workbook')) {
                    $this->assertRealWorkbook($metrics);
                }
                $this->line(json_encode($metrics, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception::class.': '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            Storage::disk('local')->delete($storedPath);
        }
    }

    private function insideRollback(callable $operation, string $storedPath, string $inputPath, string $hash): mixed
    {
        DB::beginTransaction();
        try {
            app(ProducerPriceIndicesDatasetSeeder::class)->run();
            app(ProducerPriceIndicesReferenceSeeder::class)->run();
            $dataset = StatisticalDataset::query()->where('code', 'producer_price_indices_by_product')->sole();
            $file = StatisticalSourceFile::query()->create([
                'dataset_id' => $dataset->id,
                'source_id' => null,
                'acquisition_method' => AcquisitionMethod::ManualUpload,
                'reporting_year' => 2026,
                'reporting_month' => 6,
                'original_filename' => basename($inputPath),
                'stored_path' => $storedPath,
                'storage_disk' => 'local',
                'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'file_size' => filesize($inputPath),
                'sha256' => $hash,
                'detected_at' => now(),
                'status' => SourceFileStatus::Active,
                'validation_status' => ValidationStatus::Passed,
                'validation_summary_json' => ['benchmark' => true],
            ]);

            return $operation($dataset, $file);
        } finally {
            DB::rollBack();
        }
    }

    /** @return array<string, string|null> */
    private function controlAnchors(StatisticalImport $import): array
    {
        $periods = ['2021-01-01', '2024-01-01', '2025-03-01', '2026-01-01', '2026-06-01'];

        return DB::table('statistical_observations as o')
            ->join('statistical_series as s', 's.id', '=', 'o.series_id')
            ->join('statistical_classifier_items as c', 'c.id', '=', 's.classifier_item_id')
            ->where('o.import_id', $import->id)
            ->where('c.item_code', '31.02.10.140')
            ->whereIn('o.period_start', $periods)
            ->pluck('o.value', 'o.period_start')
            ->map(fn ($value): ?string => $value === null ? null : (string) $value)
            ->all();
    }

    /** @param array<string, mixed> $metrics */
    private function assertRealWorkbook(array $metrics): void
    {
        $expected = [
            'commodityOccurrences' => 7435,
            'uniqueItems' => 1327,
            'observationsCreated' => 81582,
            'ordinary_numeric' => 81580,
            'special_footnoted' => 2,
            'missingCount' => 0,
            'errors' => 0,
        ];
        foreach ($expected as $key => $value) {
            if (($metrics[$key] ?? null) !== $value) {
                throw new RuntimeException("Real workbook regression mismatch for {$key}.");
            }
        }
        $anchors = [
            '2021-01-01' => '109.5100000000',
            '2024-01-01' => '106.8100000000',
            '2025-03-01' => '104.9600000000',
            '2026-01-01' => '109.2400000000',
            '2026-06-01' => '99.9900000000',
        ];
        foreach ($anchors as $period => $value) {
            if (($metrics['anchors'][$period] ?? null) !== $value) {
                throw new RuntimeException("Control commodity regression mismatch for {$period}.");
            }
        }
    }

    /** @param array<string, mixed> $metrics */
    private function assertRealAsyncPreview(array $metrics): void
    {
        $expected = [
            'status' => 'ready',
            'commodity_occurrences' => 7435,
            'unique_classifier_items' => 1327,
            'observation_candidates' => 81582,
            'numeric' => 81580,
            'missing' => 0,
            'footnoted' => 2,
            'fatal_errors' => 0,
        ];
        foreach ($expected as $key => $value) {
            if (($metrics[$key] ?? null) !== $value) {
                throw new RuntimeException("Real async preview regression mismatch for {$key}.");
            }
        }
    }
}
