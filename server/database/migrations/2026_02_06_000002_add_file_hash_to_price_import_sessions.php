<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add file_hash column to price_import_sessions for duplicate detection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_import_sessions', function (Blueprint $table) {
            $table->string('file_hash', 64)->nullable()->after('file_type')
                ->comment('SHA256 hash of imported file for duplicate detection');
            
            // Index for fast duplicate lookup
            $table->index(['file_hash', 'supplier_id', 'target_type'], 'pis_duplicate_check_idx');
        });
    }

    public function down(): void
    {
        Schema::table('price_import_sessions', function (Blueprint $table) {
            $table->dropIndex('pis_duplicate_check_idx');
            $table->dropColumn('file_hash');
        });
    }
};
