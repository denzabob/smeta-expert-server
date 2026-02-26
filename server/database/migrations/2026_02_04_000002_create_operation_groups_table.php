<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * operation_groups - группировка как мост к смете.
     * Например: "Распил", "Кромкооблицовка", "Сверление"
     */
    public function up(): void
    {
        Schema::create('operation_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('name');
            $table->text('note')->nullable();
            $table->string('expected_unit')->nullable()->comment('Ожидаемая единица измерения для группы');
            $table->timestamps();
            
            // Индексы
            $table->index(['user_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('operation_groups');
    }
};
