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
        Schema::create('global_normohour_sources', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('position_profile_id')->nullable();
            $table->unsignedBigInteger('region_id')->nullable();
            $table->string('source');
            $table->decimal('salary_value', 12, 2)->nullable();
            $table->decimal('salary_value_min', 12, 2)->nullable();
            $table->decimal('salary_value_max', 12, 2)->nullable();
            $table->string('salary_period')->nullable();
            $table->decimal('salary_month', 12, 2)->nullable();
            $table->decimal('hours_per_month', 10, 2)->nullable();
            $table->decimal('rate_per_hour', 10, 2)->nullable();
            $table->decimal('min_rate', 10, 2)->nullable();
            $table->decimal('max_rate', 10, 2)->nullable();
            $table->date('source_date')->nullable();
            $table->text('link')->nullable();
            $table->text('note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            
            // Foreign keys
            $table->foreign('position_profile_id')->references('id')->on('position_profiles')->onDelete('cascade');
            $table->foreign('region_id')->references('id')->on('regions')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('global_normohour_sources');
    }
};
