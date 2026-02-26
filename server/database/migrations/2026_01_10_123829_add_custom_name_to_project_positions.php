<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('project_positions', function (Blueprint $table) {
            $table->string('custom_name')->nullable()->after('edge_scheme');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_positions', function (Blueprint $table) {
            $table->dropColumn('custom_name');
        });
    }
};
