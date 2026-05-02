<?php

use App\Support\ProjectPublicId;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('projects', 'public_id')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->string('public_id', 32)->nullable()->after('id');
            });
        }

        DB::table('projects')
            ->whereNull('public_id')
            ->orderBy('id')
            ->select(['id'])
            ->chunkById(500, function ($projects): void {
                foreach ($projects as $project) {
                    DB::table('projects')
                        ->where('id', $project->id)
                        ->update(['public_id' => ProjectPublicId::generate()]);
                }
            });

        Schema::table('projects', function (Blueprint $table) {
            $table->unique('public_id', 'projects_public_id_unique');
        });

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE projects MODIFY public_id VARCHAR(32) NOT NULL');
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('projects', 'public_id')) {
            return;
        }

        Schema::table('projects', function (Blueprint $table) {
            $table->dropUnique('projects_public_id_unique');
            $table->dropColumn('public_id');
        });
    }
};
