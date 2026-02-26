<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Добавляет поле price_checked_at в таблицу materials.
 * 
 * Семантика:
 * - price_checked_at: момент времени, когда цена по карточке товара 
 *   была успешно извлечена и подтверждена парсером.
 * - Обновляется ТОЛЬКО когда парсер успешно распарсил цену.
 * - НЕ обновляется если цена не распарсилась/ошибка.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->timestamp('price_checked_at')
                ->nullable()
                ->after('availability_status')
                ->comment('Момент последней успешной проверки цены парсером');
        });
    }

    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->dropColumn('price_checked_at');
        });
    }
};
