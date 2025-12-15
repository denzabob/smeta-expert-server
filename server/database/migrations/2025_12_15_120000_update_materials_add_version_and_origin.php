<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->enum('origin', ['user', 'parser'])
                ->default('user')
                ->after('user_id');

            $table->string('last_price_screenshot_path')
                ->nullable()
                ->after('source_url');

            $table->unsignedInteger('version')
                ->default(1)
                ->after('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->dropColumn(['origin', 'last_price_screenshot_path', 'version']);
        });
    }
};


