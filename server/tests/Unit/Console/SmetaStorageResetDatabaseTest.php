<?php

namespace Tests\Unit\Console;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Throwable;

class SmetaStorageResetDatabaseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true,
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        Storage::fake('s1');
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_reset_uses_schema_nullability_and_deletes_import_mappings_before_sessions(): void
    {
        $this->createResetTables();
        Storage::disk('s1')->put('smeta/reset-test.pdf', 'test file');

        $sessionId = DB::table('import_sessions')->insertGetId([
            'file_path' => 'imports/legacy.csv',
            'storage_disk' => 'local',
        ]);
        DB::table('import_column_mappings')->insert([
            'import_session_id' => $sessionId,
            'column_index' => 0,
        ]);

        $priceImportSessionId = DB::table('price_import_sessions')->insertGetId([
            'file_path' => 'smeta/prices.csv',
            'storage_disk' => 's1',
            'raw_rows' => '[{"row":1}]',
            'file_hash' => 'abc123',
        ]);
        $priceListVersionId = DB::table('price_list_versions')->insertGetId([
            'file_path' => 'smeta/list.xlsx',
            'storage_disk' => 's1',
        ]);
        $priceImportId = DB::table('price_imports')->insertGetId([
            'file_path' => 'smeta/required-path.csv',
            'storage_disk' => 's1',
        ]);

        $artifactId = DB::table('evidence_artifacts')->insertGetId([
            'screenshot_path' => 'smeta/evidence/screenshot.png',
        ]);
        DB::table('evidence_assets')->insert([
            'evidence_artifact_id' => $artifactId,
            'file_path' => 'smeta/evidence/asset.pdf',
        ]);
        $evidenceRecordId = DB::table('evidence_records')->insertGetId(['id' => 1]);
        DB::table('generic_evidence_assets')->insert([
            'evidence_record_id' => $evidenceRecordId,
            'file_path' => 'smeta/evidence/generic.pdf',
        ]);

        $this->artisan('smeta:storage-reset', ['--confirm' => true])
            ->assertExitCode(0);

        $this->assertDatabaseMissing('import_sessions', ['id' => $sessionId]);
        $this->assertDatabaseMissing('import_column_mappings', ['import_session_id' => $sessionId]);
        $this->assertDatabaseHas('price_import_sessions', [
            'id' => $priceImportSessionId,
            'file_path' => null,
            'storage_disk' => 's1',
            'raw_rows' => null,
            'file_hash' => null,
        ]);
        $this->assertDatabaseHas('price_list_versions', [
            'id' => $priceListVersionId,
            'file_path' => null,
            'storage_disk' => 's1',
        ]);
        $this->assertDatabaseHas('price_imports', [
            'id' => $priceImportId,
            'file_path' => 'smeta/required-path.csv',
            'storage_disk' => 's1',
        ]);
        $this->assertDatabaseHas('evidence_artifacts', [
            'id' => $artifactId,
            'screenshot_path' => null,
        ]);
        $this->assertDatabaseMissing('evidence_assets', ['evidence_artifact_id' => $artifactId]);
        $this->assertDatabaseHas('evidence_records', ['id' => $evidenceRecordId]);
        $this->assertDatabaseMissing('generic_evidence_assets', ['evidence_record_id' => $evidenceRecordId]);
        Storage::disk('s1')->assertMissing('smeta/reset-test.pdf');

        $this->artisan('smeta:storage-reset', ['--confirm' => true])
            ->expectsOutputToContain('DB objects removed: 0')
            ->expectsOutputToContain('Physical files removed: 0')
            ->assertExitCode(0);
    }

    public function test_database_failure_rolls_back_and_keeps_physical_files(): void
    {
        $this->createResetTables();
        Storage::disk('s1')->put('smeta/keep-on-failure.pdf', 'preserve');
        $id = DB::table('price_import_sessions')->insertGetId([
            'file_path' => 'smeta/keep-on-failure.pdf',
            'storage_disk' => 's1',
        ]);

        DB::statement(<<<'SQL'
            CREATE TRIGGER fail_reset_update
            BEFORE UPDATE ON price_import_sessions
            BEGIN
                SELECT RAISE(ABORT, 'test database failure');
            END
            SQL);
        Log::spy();

        $this->artisan('smeta:storage-reset', ['--confirm' => true])
            ->expectsOutputToContain('DATABASE_RESET_FAILED')
            ->doesntExpectOutputToContain('test database failure')
            ->assertExitCode(1);

        $this->assertDatabaseHas('price_import_sessions', [
            'id' => $id,
            'file_path' => 'smeta/keep-on-failure.pdf',
            'storage_disk' => 's1',
        ]);
        Storage::disk('s1')->assertExists('smeta/keep-on-failure.pdf');
        Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context): bool {
            return $message === 'Smeta storage reset database failure'
                && ($context['exception'] ?? null) instanceof Throwable;
        });
    }

    private function createResetTables(): void
    {
        Schema::create('import_sessions', function (Blueprint $table): void {
            $table->id();
            $table->string('file_path');
            $table->string('storage_disk')->default('local');
        });
        Schema::create('import_column_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_session_id')->constrained('import_sessions');
            $table->unsignedInteger('column_index');
        });
        Schema::create('price_import_sessions', function (Blueprint $table): void {
            $table->id();
            $table->string('file_path')->nullable();
            $table->string('storage_disk');
            $table->text('raw_rows')->nullable();
            $table->string('file_hash')->nullable();
        });
        Schema::create('price_list_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('file_path')->nullable();
            $table->string('storage_disk');
        });
        Schema::create('price_imports', function (Blueprint $table): void {
            $table->id();
            $table->string('file_path');
            $table->string('storage_disk');
        });
        Schema::create('evidence_artifacts', function (Blueprint $table): void {
            $table->id();
            $table->string('screenshot_path')->nullable();
        });
        Schema::create('evidence_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('evidence_artifact_id')->constrained('evidence_artifacts');
            $table->string('file_path');
        });
        Schema::create('evidence_records', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('generic_evidence_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('evidence_record_id')->constrained('evidence_records');
            $table->string('file_path');
        });
    }
}
