<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_material_price_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('system_material_id')
                ->constrained('system_materials')
                ->onDelete('cascade');
            $table->unsignedInteger('version');
            $table->decimal('price_per_unit', 8, 2);
            $table->string('source_url')->nullable();
            $table->string('screenshot_path')->nullable();
            $table->timestamp('changed_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_material_price_histories');
    }
};


