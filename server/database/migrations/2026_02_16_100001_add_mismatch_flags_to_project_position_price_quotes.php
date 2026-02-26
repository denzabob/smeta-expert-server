<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add mismatch_flags JSON column to project_position_price_quotes.
 * Stores fields that did not match the canonical facade in extended mode.
 * NULL means strict mode (all fields matched) or no extended mismatch data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_position_price_quotes', function (Blueprint $table) {
            $table->json('mismatch_flags')
                  ->nullable()
                  ->after('captured_at')
                  ->comment('Fields that did not match canonical facade in extended mode');
        });
    }

    public function down(): void
    {
        Schema::table('project_position_price_quotes', function (Blueprint $table) {
            $table->dropColumn('mismatch_flags');
        });
    }
};
