<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evidence_artifacts', function (Blueprint $table) {
            // Make material_id nullable to support non-material evidence
            // (operations, labor works, expenses).
            // Must drop FK first, alter column, then re-add FK as nullable.
            $table->dropForeign(['material_id']);
        });

        Schema::table('evidence_artifacts', function (Blueprint $table) {
            $table->unsignedBigInteger('material_id')->nullable()->change();

            $table->foreign('material_id')
                ->references('id')
                ->on('materials')
                ->nullOnDelete();
        });

        Schema::table('evidence_artifacts', function (Blueprint $table) {
            $table->string('capture_source', 20)->nullable()->after('mode');
            $table->string('cost_driver_type', 20)->nullable()->after('capture_source');
        });

        // Backfill capture_source for existing rows.
        // All existing artifacts were created by the auto pipeline.
        DB::table('evidence_artifacts')
            ->whereNull('capture_source')
            ->update(['capture_source' => 'auto']);
    }

    public function down(): void
    {
        Schema::table('evidence_artifacts', function (Blueprint $table) {
            $table->dropColumn(['capture_source', 'cost_driver_type']);
        });

        // Restore material_id to NOT NULL.
        // Only safe if no NULL rows exist (which is true during rollback
        // since no non-material artifacts were created in Block A).
        Schema::table('evidence_artifacts', function (Blueprint $table) {
            $table->dropForeign(['material_id']);
        });

        Schema::table('evidence_artifacts', function (Blueprint $table) {
            $table->unsignedBigInteger('material_id')->nullable(false)->change();

            $table->foreign('material_id')
                ->references('id')
                ->on('materials')
                ->cascadeOnDelete();
        });
    }
};
