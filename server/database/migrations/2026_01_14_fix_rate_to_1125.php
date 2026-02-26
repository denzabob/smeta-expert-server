<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Fix the rate from 1000.0 to 1125.0 for profile_id=1, region_id=61
        DB::table('global_normohour_sources')
            ->where('position_profile_id', 1)
            ->where('region_id', 61)
            ->where('rate_per_hour', 1000.0)
            ->limit(1)
            ->update(['rate_per_hour' => 1125.0]);
    }

    public function down(): void
    {
        // Reverse the change
        DB::table('global_normohour_sources')
            ->where('position_profile_id', 1)
            ->where('region_id', 61)
            ->where('rate_per_hour', 1125.0)
            ->limit(1)
            ->update(['rate_per_hour' => 1000.0]);
    }
};
