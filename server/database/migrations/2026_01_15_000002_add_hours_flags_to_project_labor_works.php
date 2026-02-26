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
        Schema::table('project_labor_works', function (Blueprint $table) {
            $table->enum('hours_source', ['manual', 'from_steps'])
                ->default('manual')
                ->after('hours');
            
            $table->decimal('hours_manual', 8, 2)
                ->nullable()
                ->after('hours_source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_labor_works', function (Blueprint $table) {
            $table->dropColumn(['hours_source', 'hours_manual']);
        });
    }
};
