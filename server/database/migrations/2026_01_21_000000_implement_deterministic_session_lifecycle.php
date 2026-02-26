<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Migration: Deterministic Parsing Session Lifecycle (Anti-Loop)
 * 
 * Implements strict finite state machine for parsing sessions:
 * created → collecting → collect_done → parsing → completed
 *                    ↘                      ↘
 *                     → failed ←             → failed
 *                     → aborted ←            → aborted
 */
return new class extends Migration
{
    public function up(): void
    {
        // Step 1: Add new columns for lifecycle management
        Schema::table('parsing_sessions', function (Blueprint $table) {
            // Deterministic lifecycle status (replaces old status)
            if (!Schema::hasColumn('parsing_sessions', 'lifecycle_status')) {
                $table->string('lifecycle_status', 32)->default('created')->after('status');
            }
            
            // Collect phase tracking
            if (!Schema::hasColumn('parsing_sessions', 'collect_started_at')) {
                $table->timestamp('collect_started_at')->nullable()->after('lifecycle_status');
            }
            if (!Schema::hasColumn('parsing_sessions', 'collect_finished_at')) {
                $table->timestamp('collect_finished_at')->nullable()->after('collect_started_at');
            }
            if (!Schema::hasColumn('parsing_sessions', 'collect_urls_count')) {
                $table->unsignedInteger('collect_urls_count')->default(0)->after('collect_finished_at');
            }
            
            // Parsing phase tracking
            if (!Schema::hasColumn('parsing_sessions', 'parse_started_at')) {
                $table->timestamp('parse_started_at')->nullable()->after('collect_urls_count');
            }
            if (!Schema::hasColumn('parsing_sessions', 'parse_finished_at')) {
                $table->timestamp('parse_finished_at')->nullable()->after('parse_started_at');
            }
            
            // Anti-loop: track unique session run
            if (!Schema::hasColumn('parsing_sessions', 'session_run_id')) {
                $table->string('session_run_id', 64)->nullable()->unique()->after('parse_finished_at');
            }
            
            // Failure tracking
            if (!Schema::hasColumn('parsing_sessions', 'failed_reason')) {
                $table->string('failed_reason', 255)->nullable()->after('error_reason');
            }
            if (!Schema::hasColumn('parsing_sessions', 'failed_at')) {
                $table->timestamp('failed_at')->nullable()->after('failed_reason');
            }
            
            // Abort tracking
            if (!Schema::hasColumn('parsing_sessions', 'aborted_by')) {
                $table->string('aborted_by', 64)->nullable()->after('failed_at'); // 'user', 'timeout', 'system'
            }
            if (!Schema::hasColumn('parsing_sessions', 'aborted_at')) {
                $table->timestamp('aborted_at')->nullable()->after('aborted_by');
            }
            
            // Collect limits
            if (!Schema::hasColumn('parsing_sessions', 'max_collect_pages')) {
                $table->unsignedInteger('max_collect_pages')->nullable()->after('aborted_at');
            }
            if (!Schema::hasColumn('parsing_sessions', 'max_collect_urls')) {
                $table->unsignedInteger('max_collect_urls')->nullable()->after('max_collect_pages');
            }
            if (!Schema::hasColumn('parsing_sessions', 'max_collect_time_seconds')) {
                $table->unsignedInteger('max_collect_time_seconds')->nullable()->after('max_collect_urls');
            }
            
            // Prevent re-dispatch
            if (!Schema::hasColumn('parsing_sessions', 'job_dispatched_at')) {
                $table->timestamp('job_dispatched_at')->nullable()->after('max_collect_time_seconds');
            }
            if (!Schema::hasColumn('parsing_sessions', 'job_attempts')) {
                $table->unsignedTinyInteger('job_attempts')->default(0)->after('job_dispatched_at');
            }
        });

        // Step 2: Migrate existing data
        // Map old statuses to new lifecycle_status
        DB::statement("
            UPDATE parsing_sessions 
            SET lifecycle_status = CASE 
                WHEN status = 'pending' THEN 'created'
                WHEN status = 'running' AND full_scan_stage = 'not_started' THEN 'collecting'
                WHEN status = 'running' AND full_scan_stage = 'collecting' THEN 'collecting'
                WHEN status = 'running' AND full_scan_stage = 'collect_done' THEN 'collected'
                WHEN status = 'running' AND full_scan_stage = 'resetting' THEN 'resetting'
                WHEN status = 'running' AND full_scan_stage = 'ready_to_parse' THEN 'ready_to_parse'
                WHEN status = 'running' AND full_scan_stage IN ('reset_done', 'parsing_running') THEN 'parsing'
                WHEN status = 'running' AND full_scan_stage = 'parsing_done' THEN 'finished_success'
                WHEN status = 'completed' THEN 'finished_success'
                WHEN status = 'failed' THEN 'finished_failed'
                WHEN status = 'stopped' THEN 'finished_failed'
                WHEN status = 'canceling' THEN 'finished_failed'
                ELSE 'created'
            END
            WHERE lifecycle_status = 'created' OR lifecycle_status IS NULL
        ");

        // Step 3: Add index for lifecycle queries
        Schema::table('parsing_sessions', function (Blueprint $table) {
            $table->index(['lifecycle_status', 'supplier_name'], 'idx_lifecycle_supplier');
            $table->index(['session_run_id'], 'idx_session_run_id');
        });
    }

    public function down(): void
    {
        Schema::table('parsing_sessions', function (Blueprint $table) {
            // Drop indexes first
            $table->dropIndex('idx_lifecycle_supplier');
            $table->dropIndex('idx_session_run_id');
            
            // Drop columns
            $columns = [
                'lifecycle_status',
                'collect_started_at',
                'collect_finished_at', 
                'collect_urls_count',
                'parse_started_at',
                'parse_finished_at',
                'session_run_id',
                'failed_reason',
                'failed_at',
                'aborted_by',
                'aborted_at',
                'max_collect_pages',
                'max_collect_urls',
                'max_collect_time_seconds',
                'job_dispatched_at',
                'job_attempts',
            ];
            
            foreach ($columns as $column) {
                if (Schema::hasColumn('parsing_sessions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
