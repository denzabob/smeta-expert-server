<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Удаляем таблицы, если они существуют
        Schema::dropIfExists('system_materials');
        Schema::dropIfExists('system_material_price_histories');
        Schema::dropIfExists('user_materials');
    }

    public function down()
    {
        // Если нужно откатить миграцию, можно ничего не делать,
        // или добавить логику восстановления таблиц (если требуется)
        // Например:
        // throw new \Exception('Откат этой миграции не поддерживается.');
    }
};
