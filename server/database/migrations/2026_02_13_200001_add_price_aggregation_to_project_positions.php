<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add price aggregation fields to project_positions (facade only, additive).
 * Existing single-source behavior preserved via default price_method='single'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_positions', function (Blueprint $table) {
            $table->string('price_method', 20)->default('single')->after('total_price')
                  ->comment('single|mean|median|trimmed_mean');
            $table->unsignedSmallInteger('price_sources_count')->nullable()->after('price_method');
            $table->decimal('price_min', 12, 2)->nullable()->after('price_sources_count');
            $table->decimal('price_max', 12, 2)->nullable()->after('price_min');
        });
    }

    public function down(): void
    {
        Schema::table('project_positions', function (Blueprint $table) {
            $table->dropColumn(['price_method', 'price_sources_count', 'price_min', 'price_max']);
        });
    }
};
