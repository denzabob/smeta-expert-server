<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('revision_runs', function (Blueprint $table) {
            if (!Schema::hasColumn('revision_runs', 'project_revision_id')) {
                $table->uuid('project_revision_id')->nullable()->after('last_error');
                $table->index('project_revision_id', 'revision_runs_project_revision_id_idx');
                $table->foreign('project_revision_id')
                    ->references('id')
                    ->on('project_revisions')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('revision_runs', function (Blueprint $table) {
            if (Schema::hasColumn('revision_runs', 'project_revision_id')) {
                $table->dropForeign(['project_revision_id']);
                $table->dropIndex('revision_runs_project_revision_id_idx');
                $table->dropColumn('project_revision_id');
            }
        });
    }
};
