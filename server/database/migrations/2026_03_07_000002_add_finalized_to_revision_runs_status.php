<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `revision_runs` MODIFY `status` ENUM('PENDING','IN_PROGRESS','NEEDS_MANUAL','READY','FINALIZED','FAILED') NOT NULL DEFAULT 'PENDING'");
    }

    public function down(): void
    {
        DB::table('revision_runs')->where('status', 'FINALIZED')->update(['status' => 'READY']);
        DB::statement("ALTER TABLE `revision_runs` MODIFY `status` ENUM('PENDING','IN_PROGRESS','NEEDS_MANUAL','READY','FAILED') NOT NULL DEFAULT 'PENDING'");
    }
};
