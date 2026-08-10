<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Application\Services\StatisticalImportPreviewCacheKey;
use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Enums\SourceFileStatus;
use App\Domain\PriceIndices\Domain\Enums\StatisticalImportPreviewStatus;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesInvariantViolation;
use App\Domain\PriceIndices\Domain\Previews\StatisticalImportPreview;
use App\Domain\PriceIndices\Domain\Previews\StatisticalImportPreviewLifecycle;
use App\Domain\PriceIndices\Domain\SourceFiles\StatisticalSourceFile;
use App\Models\User;
use App\Jobs\RunStatisticalImportPreviewJob;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PriceIndicesImportPreviewModelTest extends TestCase
{
    use DatabaseTransactions;

    public function test_schema_contains_required_columns_and_indexes(): void
    {
        $this->assertTrue(Schema::hasColumns('statistical_import_previews', [
            'id', 'public_id', 'dataset_id', 'source_file_id', 'importer_code',
            'importer_version', 'status', 'cache_key', 'requested_by_user_id',
            'started_at', 'finished_at', 'failed_at', 'expires_at',
            'sheets_total', 'supported_sheets', 'ignored_sheets',
            'commodity_occurrences', 'unique_classifier_items', 'observation_candidates',
            'numeric_count', 'missing_count', 'footnoted_count', 'warnings_count',
            'fatal_errors_count', 'result_json', 'failure_code', 'failure_message',
            'metadata_json', 'created_at', 'updated_at',
        ]));

        $indexes = collect(DB::select('SHOW INDEX FROM statistical_import_previews'))
            ->pluck('Key_name')->unique()->values()->all();
        foreach ([
            'statistical_import_previews_public_id_unique',
            'stat_import_previews_cache_key_idx',
            'stat_import_previews_source_created_idx',
            'stat_import_previews_dataset_status_idx',
            'stat_import_previews_status_expires_idx',
            'stat_import_previews_created_idx',
        ] as $index) {
            $this->assertContains($index, $indexes);
        }

        $rules = collect(DB::select(<<<'SQL'
            SELECT kcu.REFERENCED_TABLE_NAME AS referenced_table, rc.DELETE_RULE AS delete_rule
            FROM information_schema.REFERENTIAL_CONSTRAINTS rc
            JOIN information_schema.KEY_COLUMN_USAGE kcu
              ON kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
             AND kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
             AND kcu.TABLE_NAME = rc.TABLE_NAME
            WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
              AND rc.TABLE_NAME = 'statistical_import_previews'
            SQL))->pluck('delete_rule', 'referenced_table');
        $this->assertSame('RESTRICT', $rules->get('statistical_datasets'));
        $this->assertSame('RESTRICT', $rules->get('statistical_source_files'));
        $this->assertSame('SET NULL', $rules->get('users'));
    }

    public function test_lifecycle_accepts_only_documented_transitions(): void
    {
        $lifecycle = app(StatisticalImportPreviewLifecycle::class);
        $valid = [
            ['pending', 'running'],
            ['pending', 'failed'],
            ['running', 'ready'],
            ['running', 'failed'],
            ['ready', 'expired'],
            ['failed', 'expired'],
        ];

        foreach ($valid as [$from, $to]) {
            $preview = new StatisticalImportPreview([
                'status' => StatisticalImportPreviewStatus::from($from),
            ]);
            $lifecycle->transition($preview, StatisticalImportPreviewStatus::from($to));
            $this->assertSame($to, $preview->status->value);
        }

        foreach ([
            ['failed', 'running'],
            ['ready', 'running'],
            ['expired', 'running'],
            ['pending', 'ready'],
        ] as [$from, $to]) {
            try {
                $lifecycle->transition(
                    new StatisticalImportPreview(['status' => StatisticalImportPreviewStatus::from($from)]),
                    StatisticalImportPreviewStatus::from($to),
                );
                $this->fail("Transition {$from} -> {$to} was accepted.");
            } catch (PriceIndicesInvariantViolation) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_model_relations_route_key_dataset_invariant_and_ready_result_immutability(): void
    {
        $dataset = StatisticalDataset::factory()->create();
        $sourceFile = StatisticalSourceFile::factory()->create([
            'dataset_id' => $dataset->id,
            'status' => SourceFileStatus::Active,
        ]);
        $user = User::factory()->create();
        $preview = StatisticalImportPreview::factory()->create([
            'dataset_id' => $dataset->id,
            'source_file_id' => $sourceFile->id,
            'requested_by_user_id' => $user->id,
        ]);

        $this->assertSame('public_id', $preview->getRouteKeyName());
        $this->assertTrue($preview->dataset->is($dataset));
        $this->assertTrue($preview->sourceFile->is($sourceFile));
        $this->assertTrue($preview->requestedBy->is($user));

        $preview->forceFill([
            'status' => StatisticalImportPreviewStatus::Ready,
            'result_json' => ['counts' => ['numeric' => 1]],
        ])->save();

        $this->expectException(PriceIndicesInvariantViolation::class);
        $preview->result_json = ['counts' => ['numeric' => 2]];
        $preview->save();
    }

    public function test_model_rejects_source_file_from_another_dataset(): void
    {
        $dataset = StatisticalDataset::factory()->create();
        $otherDataset = StatisticalDataset::factory()->create();
        $sourceFile = StatisticalSourceFile::factory()->create([
            'dataset_id' => $otherDataset->id,
        ]);

        $this->expectException(PriceIndicesInvariantViolation::class);
        StatisticalImportPreview::factory()->create([
            'dataset_id' => $dataset->id,
            'source_file_id' => $sourceFile->id,
        ]);
    }

    public function test_cache_key_uses_binary_hash_importer_code_and_version(): void
    {
        $sourceFile = StatisticalSourceFile::factory()->make([
            'sha256' => str_repeat('a', 64),
        ]);
        $otherFile = StatisticalSourceFile::factory()->make([
            'sha256' => str_repeat('b', 64),
        ]);
        $keys = app(StatisticalImportPreviewCacheKey::class);

        $first = $keys->forSourceFile($sourceFile, 'importer', '1.0.0');
        $this->assertSame($first, $keys->forSourceFile($sourceFile, 'importer', '1.0.0'));
        $this->assertNotSame($first, $keys->forSourceFile($sourceFile, 'importer', '1.0.1'));
        $this->assertNotSame($first, $keys->forSourceFile($otherFile, 'importer', '1.0.0'));
        $this->assertSame('price-indices:preview:'.$first, $keys->lockName($first));

        $job = new RunStatisticalImportPreviewJob('00000000-0000-4000-8000-000000000000');
        $this->assertSame(180, $job->timeout);
        $this->assertSame(1, $job->tries);
        $this->assertGreaterThan($job->timeout, (int) config('price_indices.imports.preview_lock_ttl'));
    }
}
