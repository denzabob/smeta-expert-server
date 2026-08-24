<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statistical_classifier_imports', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('classifier_id');
            $table->unsignedBigInteger('source_file_id');
            $table->unsignedInteger('attempt');
            $table->enum('status', [
                'pending',
                'parsing',
                'validating',
                'ready',
                'failed',
            ]);
            $table->string('parser_code', 128);
            $table->string('parser_version', 64);
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->unsignedInteger('nodes_parsed')->nullable();
            $table->unsignedInteger('sections_count')->nullable();
            $table->unsignedInteger('validation_errors_count')->default(0);
            $table->unsignedInteger('validation_warnings_count')->default(0);
            $table->json('validation_summary_json')->nullable();
            $table->json('error_json')->nullable();
            $table->timestamps();

            $table->unique(
                ['source_file_id', 'attempt'],
                'stat_cls_imports_source_attempt_unique'
            );
            $table->unique(
                ['classifier_id', 'id'],
                'stat_cls_imports_classifier_id_unique'
            );
            $table->foreign('classifier_id', 'stat_cls_imports_classifier_fk')
                ->references('id')
                ->on('statistical_classifiers')
                ->restrictOnDelete();
            $table->foreign(
                ['classifier_id', 'source_file_id'],
                'stat_cls_imports_classifier_source_fk'
            )
                ->references(['classifier_id', 'id'])
                ->on('statistical_classifier_source_files')
                ->restrictOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE statistical_classifier_imports '
                .'ADD CONSTRAINT stat_cls_imports_attempt_positive_chk CHECK (attempt > 0)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('statistical_classifier_imports');
    }
};
