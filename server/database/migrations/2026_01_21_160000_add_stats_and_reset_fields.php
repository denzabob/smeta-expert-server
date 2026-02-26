<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: Add stats JSON fields and reset tracking
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parsing_sessions', function (Blueprint $table) {
            // Reset phase tracking
            if (!Schema::hasColumn('parsing_sessions', 'reset_started_at')) {
                $table->timestamp('reset_started_at')->nullable()->after('collect_finished_at');
            }
            if (!Schema::hasColumn('parsing_sessions', 'reset_finished_at')) {
                $table->timestamp('reset_finished_at')->nullable()->after('reset_started_at');
            }
            
            // Heartbeat
            if (!Schema::hasColumn('parsing_sessions', 'last_heartbeat_at')) {
                $table->timestamp('last_heartbeat_at')->nullable()->after('last_heartbeat');
            }
            
            // Stats JSON
            if (!Schema::hasColumn('parsing_sessions', 'collect_stats_json')) {
                $table->json('collect_stats_json')->nullable()->after('collect_urls_count');
            }
            if (!Schema::hasColumn('parsing_sessions', 'parse_stats_json')) {
                $table->json('parse_stats_json')->nullable()->after('parse_finished_at');
            }
            
            // Result status (success|partial|failed)
            if (!Schema::hasColumn('parsing_sessions', 'result_status')) {
                $table->string('result_status', 16)->nullable()->after('lifecycle_status');
            }
            
            // Stop reason
            if (!Schema::hasColumn('parsing_sessions', 'stop_reason')) {
                $table->string('stop_reason', 64)->nullable()->after('error_reason');
            }
        });

        // Ensure supplier_urls has proper fields
        Schema::table('supplier_urls', function (Blueprint $table) {
            // last_seen_at
            if (!Schema::hasColumn('supplier_urls', 'last_seen_at')) {
                $table->timestamp('last_seen_at')->nullable()->after('updated_at');
            }
            
            // last_seen_session_id
            if (!Schema::hasColumn('supplier_urls', 'last_seen_session_id')) {
                $table->unsignedBigInteger('last_seen_session_id')->nullable()->after('last_seen_at');
            }
            
            // Retry fields
            if (!Schema::hasColumn('supplier_urls', 'next_retry_at')) {
                $table->timestamp('next_retry_at')->nullable()->after('attempts');
            }
            if (!Schema::hasColumn('supplier_urls', 'error_code')) {
                $table->string('error_code', 64)->nullable()->after('next_retry_at');
            }
            if (!Schema::hasColumn('supplier_urls', 'error_message')) {
                $table->string('error_message', 500)->nullable()->after('error_code');
            }
        });
        
        // Add unique index on supplier_urls if not exists
        // Using raw SQL to check index existence
        $indexExists = \DB::select("
            SELECT COUNT(*) as cnt FROM information_schema.statistics 
            WHERE table_schema = DATABASE() 
            AND table_name = 'supplier_urls' 
            AND index_name = 'idx_supplier_url_unique'
        ")[0]->cnt > 0;
        
        if (!$indexExists) {
            // First remove any duplicates (use supplier_name, not supplier)
            \DB::statement("
                DELETE t1 FROM supplier_urls t1
                INNER JOIN supplier_urls t2 
                WHERE t1.id > t2.id 
                AND t1.supplier_name = t2.supplier_name 
                AND t1.url = t2.url
            ");
            
            Schema::table('supplier_urls', function (Blueprint $table) {
                $table->unique(['supplier_name', 'url'], 'idx_supplier_url_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::table('parsing_sessions', function (Blueprint $table) {
            $columns = [
                'reset_started_at',
                'reset_finished_at',
                'last_heartbeat_at',
                'collect_stats_json',
                'parse_stats_json',
                'result_status',
                'stop_reason',
            ];
            
            foreach ($columns as $column) {
                if (Schema::hasColumn('parsing_sessions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('supplier_urls', function (Blueprint $table) {
            $columns = [
                'last_seen_at',
                'last_seen_session_id',
                'next_retry_at',
                'error_code',
                'error_message',
            ];
            
            foreach ($columns as $column) {
                if (Schema::hasColumn('supplier_urls', $column)) {
                    $table->dropColumn($column);
                }
            }
            
            // Drop unique index
            try {
                $table->dropIndex('idx_supplier_url_unique');
            } catch (\Exception $e) {
                // Index doesn't exist
            }
        });
    }
};
