<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Расширение materials для поддержки фасадов:
 * - type: добавить 'facade'
 * - metadata: JSON-поле для фасадных атрибутов (base, thickness, finish)
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Расширить enum type: plate, edge -> plate, edge, facade
        DB::statement("ALTER TABLE `materials` MODIFY `type` ENUM('plate','edge','facade') NOT NULL DEFAULT 'plate'");

        // 2. Добавить metadata JSON
        if (!Schema::hasColumn('materials', 'metadata')) {
            Schema::table('materials', function (Blueprint $table) {
                $table->json('metadata')->nullable()->after('operation_ids');
            });
        }
    }

    public function down(): void
    {
        // Убрать facade из enum
        DB::statement("ALTER TABLE `materials` MODIFY `type` ENUM('plate','edge') NOT NULL DEFAULT 'plate'");

        if (Schema::hasColumn('materials', 'metadata')) {
            Schema::table('materials', function (Blueprint $table) {
                $table->dropColumn('metadata');
            });
        }
    }
};
