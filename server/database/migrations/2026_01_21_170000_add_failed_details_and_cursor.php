<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: Add failed_details LONGTEXT and cursor table for resume
 * 
 * Purpose:
 * 1. failed_details LONGTEXT - store full error payload (exit_code, stderr, exception)
 * 2. failed_reason VARCHAR(100) - short error code only (PROCESS_TIMEOUT, etc.)
 * 3. parser_collect_cursors - resume point for collect phase
 */
return new class extends Migration
{
    public function up(): void
    {
        // ====================
        // 1. Add failed_details to parsing_sessions
        // ====================
        Schema::table('parsing_sessions', function (Blueprint $table) {
            // Full error payload (JSON)
            if (!Schema::hasColumn('parsing_sessions', 'failed_details')) {
                $table->longText('failed_details')->nullable()->after('failed_reason');
            }
        });
        
        // Modify failed_reason to be short (100 chars max for error codes)
        // First, truncate existing long values
        \DB::statement("
            UPDATE parsing_sessions 
            SET failed_reason = LEFT(failed_reason, 100)
            WHERE LENGTH(failed_reason) > 100
        ");
        
        // ====================
        // 2. Create cursor table for collect resume
        // ====================
        if (!Schema::hasTable('parser_collect_cursors')) {
            Schema::create('parser_collect_cursors', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('session_id')->unique();
                $table->string('supplier_name', 64);
                
                // Current position
                $table->string('current_category', 255)->nullable();
                $table->unsignedInteger('current_page')->default(0);
                $table->unsignedInteger('visited_pages')->default(0);
                
                // Stats
                $table->unsignedInteger('urls_found_total')->default(0);
                $table->unsignedInteger('urls_unique_total')->default(0);
                $table->unsignedInteger('urls_sent_total')->default(0);
                $table->unsignedInteger('duplicates_dropped')->default(0);
                
                // Time tracking
                $table->decimal('elapsed_seconds', 10, 2)->default(0);
                $table->timestamp('last_chunk_sent_at')->nullable();
                
                // Stop info
                $table->string('stop_reason', 64)->nullable();
                $table->boolean('is_complete')->default(false);
                
                $table->timestamps();
                
                // Indexes
                $table->index(['supplier_name', 'is_complete']);
                
                // Foreign key
                $table->foreign('session_id')
                    ->references('id')
                    ->on('parsing_sessions')
                    ->onDelete('cascade');
            });
        }
        
        // ====================
        // 3. Add chunk tracking to supplier_urls
        // ====================
        Schema::table('supplier_urls', function (Blueprint $table) {
            // Track which chunk/batch this URL was collected in
            if (!Schema::hasColumn('supplier_urls', 'collect_chunk_id')) {
                $table->unsignedInteger('collect_chunk_id')->nullable()->after('last_seen_session_id');
            }
        });
    }

    public function down(): void
    {
        // Drop cursor table
        Schema::dropIfExists('parser_collect_cursors');
        
        // Remove columns
        Schema::table('parsing_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('parsing_sessions', 'failed_details')) {
                $table->dropColumn('failed_details');
            }
        });
        
        Schema::table('supplier_urls', function (Blueprint $table) {
            if (Schema::hasColumn('supplier_urls', 'collect_chunk_id')) {
                $table->dropColumn('collect_chunk_id');
            }
        });
    }
};
