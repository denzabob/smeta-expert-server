<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_price_histories', function (Blueprint $table) {
            if (!Schema::hasColumn('material_price_histories', 'evidence_artifact_id')) {
                $table->foreignId('evidence_artifact_id')
                    ->nullable()
                    ->after('normalized_source_url')
                    ->constrained('evidence_artifacts')
                    ->nullOnDelete();
            }
            if (!Schema::hasColumn('material_price_histories', 'evidence_mode')) {
                $table->enum('evidence_mode', ['auto', 'manual'])->nullable()->after('evidence_artifact_id');
            }
            if (!Schema::hasColumn('material_price_histories', 'is_auto_verified')) {
                $table->boolean('is_auto_verified')->nullable()->after('evidence_mode');
            }
            if (!Schema::hasColumn('material_price_histories', 'validation_confidence')) {
                $table->unsignedTinyInteger('validation_confidence')->nullable()->after('is_auto_verified');
            }
        });

        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE material_price_histories MODIFY price_per_unit DECIMAL(12,2) NOT NULL');
        }

        Schema::table('revision_run_items', function (Blueprint $table) {
            if (!Schema::hasColumn('revision_run_items', 'state')) {
                $table->string('state', 32)->nullable()->after('status');
            }
            if (!Schema::hasColumn('revision_run_items', 'stage')) {
                $table->string('stage', 64)->nullable()->after('state');
            }
            if (!Schema::hasColumn('revision_run_items', 'reason_code')) {
                $table->string('reason_code', 64)->nullable()->after('stage');
            }
            if (!Schema::hasColumn('revision_run_items', 'attempt_count')) {
                $table->unsignedSmallInteger('attempt_count')->default(0)->after('reason_code');
            }
            if (!Schema::hasColumn('revision_run_items', 'last_error_at')) {
                $table->timestamp('last_error_at')->nullable()->after('attempt_count');
            }
            if (!Schema::hasColumn('revision_run_items', 'diagnostics_json')) {
                $table->json('diagnostics_json')->nullable()->after('last_error_at');
            }
        });

        if (Schema::hasColumn('revision_run_items', 'revision_run_id') && Schema::hasColumn('revision_run_items', 'state')) {
            Schema::table('revision_run_items', function (Blueprint $table) {
                $table->index(['revision_run_id', 'state'], 'revision_run_items_run_state_idx');
            });
        }
    }

    public function down(): void
    {
        try {
            Schema::table('revision_run_items', function (Blueprint $table) {
                $table->dropIndex('revision_run_items_run_state_idx');
            });
        } catch (\Throwable $e) {
            // Ignore missing index on rollback.
        }

        Schema::table('revision_run_items', function (Blueprint $table) {
            $toDrop = [];
            foreach (['diagnostics_json', 'last_error_at', 'attempt_count', 'reason_code', 'stage', 'state'] as $col) {
                if (Schema::hasColumn('revision_run_items', $col)) {
                    $toDrop[] = $col;
                }
            }
            if (!empty($toDrop)) {
                $table->dropColumn($toDrop);
            }
        });

        Schema::table('material_price_histories', function (Blueprint $table) {
            if (Schema::hasColumn('material_price_histories', 'evidence_artifact_id')) {
                try {
                    $table->dropConstrainedForeignId('evidence_artifact_id');
                } catch (\Throwable $e) {
                    try {
                        $table->dropForeign(['evidence_artifact_id']);
                        $table->dropColumn('evidence_artifact_id');
                    } catch (\Throwable $ignored) {
                        // Ignore rollback inconsistencies.
                    }
                }
            }

            $toDrop = [];
            foreach (['validation_confidence', 'is_auto_verified', 'evidence_mode'] as $col) {
                if (Schema::hasColumn('material_price_histories', $col)) {
                    $toDrop[] = $col;
                }
            }
            if (!empty($toDrop)) {
                $table->dropColumn($toDrop);
            }
        });

        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE material_price_histories MODIFY price_per_unit DECIMAL(8,2) NOT NULL');
        }
    }
};

