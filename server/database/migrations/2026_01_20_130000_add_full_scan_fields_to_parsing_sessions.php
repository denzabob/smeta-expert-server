<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parsing_sessions', function (Blueprint $table) {
            // Full-scan idempotency fields
            $table->string('full_scan_run_id', 64)->nullable()->after('total_urls');
            $table->timestamp('full_scan_prepared_at')->nullable()->after('full_scan_run_id');
            $table->enum('full_scan_stage', [
                'not_started',
                'collect_done', 
                'reset_done',
                'parsing_running',
                'parsing_done'
            ])->default('not_started')->after('full_scan_prepared_at');
            
            // Error tracking
            $table->string('error_reason', 255)->nullable()->after('full_scan_stage');
        });
    }

    public function down(): void
    {
        Schema::table('parsing_sessions', function (Blueprint $table) {
            $table->dropColumn([
                'full_scan_run_id',
                'full_scan_prepared_at', 
                'full_scan_stage',
                'error_reason',
            ]);
        });
    }
};
