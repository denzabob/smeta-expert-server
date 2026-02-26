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
        Schema::create('project_labor_works', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('title', 255);  // "Модификация модуля №8 (мойка)"
            $table->string('basis', 500)->nullable();  // "п. 5.2.4 ГОСТ 16371-2014"
            $table->decimal('hours', 8, 2);  // 2.00
            $table->text('note')->nullable();  // пояснение/что включено
            $table->integer('sort_order')->default(0);  // порядок вывода
            $table->timestamps();
            
            // Индекс для быстрого поиска по проекту и сортировке
            $table->index(['project_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_labor_works');
    }
};
