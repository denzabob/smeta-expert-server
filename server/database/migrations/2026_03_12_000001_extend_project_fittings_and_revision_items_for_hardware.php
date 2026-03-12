<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_fittings', function (Blueprint $table) {
            if (!Schema::hasColumn('project_fittings', 'material_id')) {
                $table->foreignId('material_id')
                    ->nullable()
                    ->after('project_id')
                    ->constrained('materials')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('project_fittings', 'source_url')) {
                $table->text('source_url')->nullable()->after('unit_price');
            }
        });

        Schema::table('revision_run_items', function (Blueprint $table) {
            if (!Schema::hasColumn('revision_run_items', 'project_fitting_id')) {
                $table->foreignId('project_fitting_id')
                    ->nullable()
                    ->after('project_position_id')
                    ->constrained('project_fittings')
                    ->nullOnDelete();
            }
        });

        if (Schema::hasColumn('revision_run_items', 'project_position_id')) {
            try {
                Schema::table('revision_run_items', function (Blueprint $table) {
                    $table->dropForeign(['project_position_id']);
                });
            } catch (\Throwable $e) {
                // Ignore missing foreign key edge cases.
            }

            if (DB::getDriverName() === 'mysql') {
                DB::statement('ALTER TABLE revision_run_items MODIFY project_position_id BIGINT UNSIGNED NULL');
            } else {
                Schema::table('revision_run_items', function (Blueprint $table) {
                    $table->unsignedBigInteger('project_position_id')->nullable()->change();
                });
            }

            try {
                Schema::table('revision_run_items', function (Blueprint $table) {
                    $table->foreign('project_position_id')
                        ->references('id')
                        ->on('project_positions')
                        ->nullOnDelete();
                });
            } catch (\Throwable $e) {
                // Ignore if FK already exists with compatible definition.
            }
        }
    }

    public function down(): void
    {
        try {
            Schema::table('revision_run_items', function (Blueprint $table) {
                if (Schema::hasColumn('revision_run_items', 'project_fitting_id')) {
                    $table->dropConstrainedForeignId('project_fitting_id');
                }
            });
        } catch (\Throwable $e) {
            // Ignore rollback inconsistencies.
        }

        Schema::table('project_fittings', function (Blueprint $table) {
            if (Schema::hasColumn('project_fittings', 'source_url')) {
                $table->dropColumn('source_url');
            }

            if (Schema::hasColumn('project_fittings', 'material_id')) {
                try {
                    $table->dropConstrainedForeignId('material_id');
                } catch (\Throwable $e) {
                    try {
                        $table->dropForeign(['material_id']);
                        $table->dropColumn('material_id');
                    } catch (\Throwable $ignored) {
                        // Ignore rollback inconsistencies.
                    }
                }
            }
        });
    }
};
