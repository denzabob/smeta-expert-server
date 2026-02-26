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
        Schema::create('project_normohour_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->onDelete('cascade');
            $table->enum('source', [
                'hh_ru',
                'avito',
                'company_site',
                'proposal'
            ])->comment('Источник ставки');
            $table->string('position_profile', 255)->nullable()->comment('Должность/профиль');
            $table->string('salary_range', 255)->nullable()->comment('Вилка/значение зарплаты (на руки)');
            $table->enum('period', ['month', 'year'])->default('month')->comment('Период (месяц/год)');
            $table->string('link', 500)->nullable()->comment('Ссылка на источник');
            $table->text('note')->nullable()->comment('Примечание');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_normohour_sources');
    }
};
