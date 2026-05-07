<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_settings')) {
            Schema::table('user_settings', function (Blueprint $table) {
                if (!Schema::hasColumn('user_settings', 'facade_width_allowance_mm')) {
                    $table->unsignedSmallInteger('facade_width_allowance_mm')
                        ->default(0)
                        ->comment('Припуск фасада по ширине, мм');
                }

                if (!Schema::hasColumn('user_settings', 'facade_height_allowance_mm')) {
                    $table->unsignedSmallInteger('facade_height_allowance_mm')
                        ->default(0)
                        ->comment('Припуск фасада по высоте, мм');
                }
            });
        }

        if (Schema::hasTable('projects')) {
            Schema::table('projects', function (Blueprint $table) {
                if (!Schema::hasColumn('projects', 'facade_width_allowance_mm')) {
                    $table->unsignedSmallInteger('facade_width_allowance_mm')
                        ->default(0)
                        ->comment('Припуск фасада по ширине, мм');
                }

                if (!Schema::hasColumn('projects', 'facade_height_allowance_mm')) {
                    $table->unsignedSmallInteger('facade_height_allowance_mm')
                        ->default(0)
                        ->comment('Припуск фасада по высоте, мм');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('projects')) {
            Schema::table('projects', function (Blueprint $table) {
                if (Schema::hasColumn('projects', 'facade_height_allowance_mm')) {
                    $table->dropColumn('facade_height_allowance_mm');
                }

                if (Schema::hasColumn('projects', 'facade_width_allowance_mm')) {
                    $table->dropColumn('facade_width_allowance_mm');
                }
            });
        }

        if (Schema::hasTable('user_settings')) {
            Schema::table('user_settings', function (Blueprint $table) {
                if (Schema::hasColumn('user_settings', 'facade_height_allowance_mm')) {
                    $table->dropColumn('facade_height_allowance_mm');
                }

                if (Schema::hasColumn('user_settings', 'facade_width_allowance_mm')) {
                    $table->dropColumn('facade_width_allowance_mm');
                }
            });
        }
    }
};
