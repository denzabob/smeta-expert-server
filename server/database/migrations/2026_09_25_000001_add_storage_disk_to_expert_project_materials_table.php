<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expert_project_materials', function (Blueprint $table) {
            $table->string('storage_disk', 32)->nullable()->after('storage_path');
        });
    }

    public function down(): void
    {
        if (DB::table('expert_project_materials')
            ->whereNotNull('storage_disk')
            ->where('storage_disk', '!=', 'local')
            ->exists()) {
            throw new RuntimeException('Cannot remove Expert storage disk locators while non-local materials exist.');
        }

        Schema::table('expert_project_materials', function (Blueprint $table) {
            $table->dropColumn('storage_disk');
        });
    }
};
