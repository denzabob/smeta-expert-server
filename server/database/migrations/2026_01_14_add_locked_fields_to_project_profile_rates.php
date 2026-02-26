<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Добавляет поля для отслеживания зафиксированных (locked) ставок:
     * - is_locked: флаг что ставка зафиксирована пользователем (не может быть изменена автоматически)
     * - locked_at: время когда ставка была зафиксирована
     * - locked_reason: причина фиксации (например "Зафиксировано пользователем вручную")
     */
    public function up(): void
    {
        Schema::table('project_profile_rates', function (Blueprint $table) {
            // Флаг что ставка зафиксирована (is_locked=1)
            if (!Schema::hasColumn('project_profile_rates', 'is_locked')) {
                $table->boolean('is_locked')->default(false)->after('calculation_method');
            }

            // Время фиксации
            if (!Schema::hasColumn('project_profile_rates', 'locked_at')) {
                $table->timestamp('locked_at')->nullable()->after('is_locked');
            }

            // Причина фиксации
            if (!Schema::hasColumn('project_profile_rates', 'locked_reason')) {
                $table->string('locked_reason')->nullable()->after('locked_at');
            }
        });

        // Добавить индекс если его нет
        if (Schema::hasColumn('project_profile_rates', 'is_locked')) {
            $hasIndex = DB::select("SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS 
                WHERE table_name = 'project_profile_rates' 
                AND column_name = 'project_id' 
                AND seq_in_index = 1 
                AND index_name LIKE '%project_id_profile_id_is_locked%'
                LIMIT 1");
            
            if (empty($hasIndex)) {
                Schema::table('project_profile_rates', function (Blueprint $table) {
                    $table->index(['project_id', 'profile_id', 'is_locked']);
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_profile_rates', function (Blueprint $table) {
            // Попытаться удалить индекс если он есть
            $table->dropIndexIfExists(['project_id', 'profile_id', 'is_locked']);
            
            // Удалить колонки если они есть
            if (Schema::hasColumn('project_profile_rates', 'is_locked')) {
                $table->dropColumn('is_locked');
            }
            if (Schema::hasColumn('project_profile_rates', 'locked_at')) {
                $table->dropColumn('locked_at');
            }
            if (Schema::hasColumn('project_profile_rates', 'locked_reason')) {
                $table->dropColumn('locked_reason');
            }
        });
    }
};
