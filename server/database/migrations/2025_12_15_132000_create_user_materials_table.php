<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('article');
            $table->enum('type', ['plate', 'edge', 'fitting']);
            $table->enum('unit', ['м²', 'м.п.', 'шт']);
            $table->decimal('price_per_unit', 8, 2);
            $table->string('supplier')->nullable(); // «Пользователь» или конкретный поставщик
            $table->string('source_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_materials');
    }
};


