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
        Schema::create('project_labor_work_steps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_labor_work_id');
            $table->string('title');
            $table->string('basis')->nullable();
            $table->string('input_data')->nullable();
            $table->decimal('hours', 8, 2)->default(0.00);
            $table->text('note')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            // Indexes
            $table->index(['project_labor_work_id', 'sort_order'], 'idx_parent_sort');

            // Foreign key
            $table->foreign('project_labor_work_id')
                ->references('id')
                ->on('project_labor_works')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_labor_work_steps');
    }
};
