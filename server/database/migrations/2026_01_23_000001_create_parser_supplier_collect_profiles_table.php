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
        Schema::create('parser_supplier_collect_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('supplier_name');
            $table->string('name');
            $table->json('config_override');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['supplier_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parser_supplier_collect_profiles');
    }
};
