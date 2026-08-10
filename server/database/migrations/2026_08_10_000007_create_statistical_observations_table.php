<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statistical_observations', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('import_id')
                ->constrained('statistical_imports')
                ->restrictOnDelete();
            $table->foreignId('series_id')
                ->constrained('statistical_series')
                ->restrictOnDelete();
            $table->date('period_start');
            $table->decimal('value', 20, 10)->nullable();
            $table->string('missing_reason', 64)->nullable();
            $table->foreignId('source_file_id')
                ->constrained('statistical_source_files')
                ->restrictOnDelete();
            $table->string('sheet_name');
            $table->unsignedInteger('source_row');
            $table->string('source_column', 16);
            $table->string('source_cell_address', 32)->nullable();
            $table->string('source_value_raw')->nullable();
            $table->string('footnote_marker', 64)->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(
                ['import_id', 'series_id', 'period_start'],
                'stat_observations_import_series_period_unique'
            );
            $table->index(['series_id', 'period_start'], 'stat_observations_series_period_idx');
            $table->index(['import_id', 'series_id'], 'stat_observations_import_series_idx');
            $table->index(['import_id', 'period_start'], 'stat_observations_import_period_idx');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE statistical_observations '
                .'ADD CONSTRAINT stat_observations_value_missing_chk '
                .'CHECK ((value IS NOT NULL AND missing_reason IS NULL) '
                .'OR (value IS NULL AND missing_reason IS NOT NULL))'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('statistical_observations');
    }
};
