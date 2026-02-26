<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_price_histories', function (Blueprint $table) {
            // Region for price observation
            $table->unsignedBigInteger('region_id')->nullable()->after('material_id');

            // Observation timestamp (primary temporal field for price observations)
            $table->timestamp('observed_at')->useCurrent()->after('region_id');

            // Source type
            $table->enum('source_type', ['web', 'manual', 'price_list', 'chrome_ext'])->default('manual')->after('observed_at');

            // Link to parsing session
            $table->unsignedBigInteger('parse_session_id')->nullable()->after('source_type');

            // Snapshot for evidence / proof
            $table->string('snapshot_path', 255)->nullable()->after('parse_session_id');

            // Verification flag
            $table->tinyInteger('is_verified')->default(0)->after('snapshot_path');

            // Currency
            $table->string('currency', 3)->default('RUB')->after('is_verified');

            // Availability
            $table->string('availability', 50)->nullable()->after('currency');

            // Foreign keys
            $table->foreign('region_id')->references('id')->on('regions')->onDelete('set null');
            $table->foreign('parse_session_id')->references('id')->on('parsing_sessions')->onDelete('set null');

            // Indexes for efficient queries
            $table->index(['material_id', 'region_id', 'observed_at'], 'mph_material_region_observed_idx');
            $table->index(['material_id', 'observed_at'], 'mph_material_observed_idx');
        });
    }

    public function down(): void
    {
        Schema::table('material_price_histories', function (Blueprint $table) {
            $table->dropIndex('mph_material_region_observed_idx');
            $table->dropIndex('mph_material_observed_idx');
            $table->dropForeign(['region_id']);
            $table->dropForeign(['parse_session_id']);
            $table->dropColumn([
                'region_id', 'observed_at', 'source_type', 'parse_session_id',
                'snapshot_path', 'is_verified', 'currency', 'availability',
            ]);
        });
    }
};
