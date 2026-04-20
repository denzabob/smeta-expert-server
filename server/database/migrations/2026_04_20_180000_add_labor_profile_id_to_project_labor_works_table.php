<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_labor_works', function (Blueprint $table) {
            $table->unsignedBigInteger('labor_profile_id')->nullable()->after('position_profile_id');
            $table->index('labor_profile_id');
        });
    }

    public function down(): void
    {
        Schema::table('project_labor_works', function (Blueprint $table) {
            $table->dropIndex(['labor_profile_id']);
            $table->dropColumn('labor_profile_id');
        });
    }
};
