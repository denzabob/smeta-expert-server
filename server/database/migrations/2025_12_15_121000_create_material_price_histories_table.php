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
        Schema::create('material_price_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')
                ->constrained('materials')
                ->onDelete('cascade');
            $table->unsignedInteger('version');
            $table->decimal('price_per_unit', 8, 2);
            $table->string('source_url')->nullable();
            $table->string('screenshot_path')->nullable();
            $table->timestamp('changed_at')->useCurrent();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('material_price_histories');
    }
};


