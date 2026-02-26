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
        Schema::table('expenses', function (Blueprint $table) {
            // Переименуем колонки если они существуют
            if (Schema::hasColumn('expenses', 'type')) {
                $table->renameColumn('type', 'name');
            }
            if (Schema::hasColumn('expenses', 'cost')) {
                $table->renameColumn('cost', 'amount');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            // Восстанавливаем старые названия
            if (Schema::hasColumn('expenses', 'name')) {
                $table->renameColumn('name', 'type');
            }
            if (Schema::hasColumn('expenses', 'amount')) {
                $table->renameColumn('amount', 'cost');
            }
        });
    }
};
