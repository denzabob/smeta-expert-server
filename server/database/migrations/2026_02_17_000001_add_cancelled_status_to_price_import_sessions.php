<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Modify the ENUM type to include 'cancelled' status
        DB::statement("
            ALTER TABLE price_import_sessions 
            MODIFY COLUMN status ENUM(
                'created',
                'parsing_failed',
                'mapping_required',
                'resolution_required',
                'execution_running',
                'completed',
                'execution_failed',
                'cancelled'
            ) DEFAULT 'created'
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove 'cancelled' status from ENUM
        // Note: This will fail if there are any rows with 'cancelled' status
        DB::statement("
            ALTER TABLE price_import_sessions 
            MODIFY COLUMN status ENUM(
                'created',
                'parsing_failed',
                'mapping_required',
                'resolution_required',
                'execution_running',
                'completed',
                'execution_failed'
            ) DEFAULT 'created'
        ");
    }
};
