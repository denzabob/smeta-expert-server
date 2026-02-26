<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Extend type ENUM to include 'hardware'
        DB::statement("ALTER TABLE `materials` MODIFY COLUMN `type` ENUM('plate','edge','facade','hardware') NOT NULL DEFAULT 'plate'");

        Schema::table('materials', function (Blueprint $table) {
            // Visibility / curation
            $table->enum('visibility', ['private', 'public', 'curated'])->default('private')->after('is_active');
            $table->unsignedBigInteger('curator_user_id')->nullable()->after('visibility');
            $table->timestamp('published_at')->nullable()->after('curator_user_id');
            $table->timestamp('curated_at')->nullable()->after('published_at');

            // Trust / status
            $table->unsignedSmallInteger('trust_score')->default(0)->after('curated_at');
            $table->enum('trust_level', ['unverified', 'partial', 'verified'])->default('unverified')->after('trust_score');
            $table->enum('data_origin', ['manual', 'url_parse', 'price_list', 'chrome_ext'])->default('manual')->after('trust_level');
            $table->timestamp('last_parsed_at')->nullable()->after('data_origin');
            $table->enum('last_parse_status', ['ok', 'failed', 'blocked', 'unsupported'])->nullable()->after('last_parsed_at');
            $table->string('last_parse_error', 255)->nullable()->after('last_parse_status');

            // Region (optional, for region-specific materials)
            $table->unsignedBigInteger('region_id')->nullable()->after('last_parse_error');

            // Foreign keys
            $table->foreign('curator_user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('region_id')->references('id')->on('regions')->onDelete('set null');

            // Indexes
            $table->index(['region_id', 'visibility', 'type'], 'materials_region_visibility_idx');
            $table->index(['visibility', 'type'], 'materials_visibility_type_idx');
        });
    }

    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->dropIndex('materials_region_visibility_idx');
            $table->dropIndex('materials_visibility_type_idx');
            $table->dropForeign(['curator_user_id']);
            $table->dropForeign(['region_id']);
            $table->dropColumn([
                'visibility', 'curator_user_id', 'published_at', 'curated_at',
                'trust_score', 'trust_level', 'data_origin',
                'last_parsed_at', 'last_parse_status', 'last_parse_error',
                'region_id',
            ]);
        });

        DB::statement("ALTER TABLE `materials` MODIFY COLUMN `type` ENUM('plate','edge','facade') NOT NULL DEFAULT 'plate'");
    }
};
