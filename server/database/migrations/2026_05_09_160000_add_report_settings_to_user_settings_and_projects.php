<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_settings') && !Schema::hasColumn('user_settings', 'report_settings')) {
            Schema::table('user_settings', function (Blueprint $table) {
                $table->json('report_settings')->nullable()->after('text_blocks');
            });
        }

        if (Schema::hasTable('projects') && !Schema::hasColumn('projects', 'report_settings')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->json('report_settings')->nullable()->after('text_blocks');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('projects') && Schema::hasColumn('projects', 'report_settings')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->dropColumn('report_settings');
            });
        }

        if (Schema::hasTable('user_settings') && Schema::hasColumn('user_settings', 'report_settings')) {
            Schema::table('user_settings', function (Blueprint $table) {
                $table->dropColumn('report_settings');
            });
        }
    }
};
