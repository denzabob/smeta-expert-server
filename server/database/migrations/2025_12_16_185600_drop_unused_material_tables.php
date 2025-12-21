<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        // Удаляем избыточные таблицы
        Schema::dropIfExists('system_materials');
        Schema::dropIfExists('system_material_price_histories');
        Schema::dropIfExists('user_materials');
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        // Восстановление только если необходимо (не рекомендуется для production)
        Schema::create('system_materials', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('article');
            $table->enum('type', ['plate', 'edge', 'fitting']);
            $table->enum('unit', ['м²', 'м.п.', 'шт']);
            $table->decimal('price_per_unit', 8, 2);
            $table->string('supplier')->nullable();
            $table->string('source_url')->nullable();
            $table->string('screenshot_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('system_material_price_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('system_material_id')->constrained('system_materials')->onDelete('cascade');
            $table->unsignedInteger('version');
            $table->decimal('price_per_unit', 8, 2);
            $table->string('source_url')->nullable();
            $table->string('screenshot_path')->nullable();
            $table->timestamp('changed_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('user_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('article');
            $table->enum('type', ['plate', 'edge', 'fitting']);
            $table->enum('unit', ['м²', 'м.п.', 'шт']);
            $table->decimal('price_per_unit', 8, 2);
            $table->string('source_url')->nullable();
            $table->string('last_price_screenshot_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
    }
};
