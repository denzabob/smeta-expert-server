<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_materials', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('article');
            $table->enum('type', ['plate', 'edge', 'fitting']);
            $table->enum('unit', ['м²', 'м.п.', 'шт']);
            $table->decimal('price_per_unit', 8, 2);
            $table->string('supplier')->nullable(); // название поставщика
            $table->string('source_url')->nullable();
            $table->string('screenshot_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_materials');
    }
};


