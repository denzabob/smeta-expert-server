<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `revision_run_items` MODIFY `status` ENUM('PENDING','OK','BLOCKED','TIMEOUT','PARSE_ERROR','NO_TEMPLATE','NEEDS_MANUAL') NOT NULL DEFAULT 'PENDING'");
    }

    public function down(): void
    {
        // Revert any PENDING rows to NEEDS_MANUAL before removing the value
        DB::table('revision_run_items')->where('status', 'PENDING')->update(['status' => 'NEEDS_MANUAL']);
        DB::statement("ALTER TABLE `revision_run_items` MODIFY `status` ENUM('OK','BLOCKED','TIMEOUT','PARSE_ERROR','NO_TEMPLATE','NEEDS_MANUAL') NOT NULL DEFAULT 'NEEDS_MANUAL'");
    }
};
