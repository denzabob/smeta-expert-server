<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('revision_run_items', function (Blueprint $table) {
            $table->string('cost_driver_type', 20)->nullable()->after('price_history_id');
            $table->string('evidence_subject_type', 100)->nullable()->after('cost_driver_type');
            $table->unsignedBigInteger('evidence_subject_id')->nullable()->after('evidence_subject_type');

            $table->index(
                ['revision_run_id', 'cost_driver_type'],
                'revision_run_items_run_driver_idx'
            );
            $table->index(
                ['evidence_subject_type', 'evidence_subject_id'],
                'revision_run_items_subject_idx'
            );
        });

        // Backfill cost_driver_type for existing rows based on FK presence.
        // Fittings: project_fitting_id IS NOT NULL → 'fitting'
        DB::table('revision_run_items')
            ->whereNotNull('project_fitting_id')
            ->whereNull('cost_driver_type')
            ->update(['cost_driver_type' => 'fitting']);

        // Remaining rows are material-based positions — default to 'plate'.
        // NOTE: this naive backfill misclassifies edge and facade items.
        // The corrective migration 2026_03_28_100001_fix_cost_driver_type_backfill
        // runs immediately after and repairs all rows to the correct type.
        DB::table('revision_run_items')
            ->whereNull('cost_driver_type')
            ->update(['cost_driver_type' => 'plate']);
    }

    public function down(): void
    {
        Schema::table('revision_run_items', function (Blueprint $table) {
            $table->dropIndex('revision_run_items_run_driver_idx');
            $table->dropIndex('revision_run_items_subject_idx');
            $table->dropColumn(['cost_driver_type', 'evidence_subject_type', 'evidence_subject_id']);
        });
    }
};
