<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Переводим created_by_user_id в nullable с nullOnDelete поведением
     * чтобы при удалении пользователя ревизии сохранялись с NULL creator
     */
    public function up(): void
    {
        Schema::table('project_revisions', function (Blueprint $table) {
            // Удаляем старый foreign key (на restrict)
            $table->dropForeign(['created_by_user_id']);
            
            // Изменяем столбец на nullable
            $table->unsignedBigInteger('created_by_user_id')->nullable()->change();
            
            // Добавляем новый foreign key с nullOnDelete
            $table->foreign('created_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_revisions', function (Blueprint $table) {
            // Удаляем новый foreign key
            $table->dropForeign(['created_by_user_id']);
            
            // Возвращаем столбец в NOT NULL состояние
            $table->unsignedBigInteger('created_by_user_id')->change();
            
            // Восстанавливаем старый foreign key
            $table->foreign('created_by_user_id')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');
        });
    }
};
