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
        Schema::create('details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained('furniture_modules')->onDelete('cascade');
            $table->string('name');
            $table->integer('width_mm');
            $table->integer('height_mm');
            $table->integer('quantity');
            $table->foreignId('material_id')->constrained('materials')->onDelete('cascade');
            $table->string('edge_type')->nullable();
            $table->json('edge_config')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('details');
    }
};
