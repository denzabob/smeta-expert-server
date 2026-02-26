<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Инициализирует статусы для существующих supplier_urls.
 * 
 * Все существующие валидные URL переходят в pending,
 * невалидные - в blocked.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Валидные URL → pending (готовы к парсингу)
        DB::table('supplier_urls')
            ->where('is_valid', true)
            ->whereNull('status')
            ->orWhere('status', '')
            ->update(['status' => 'pending']);
        
        // Невалидные URL → blocked
        DB::table('supplier_urls')
            ->where('is_valid', false)
            ->update(['status' => 'blocked']);
    }

    public function down(): void
    {
        // Сбрасываем все статусы в pending
        DB::table('supplier_urls')->update(['status' => 'pending']);
    }
};
