<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-position price quotes table.
 * Stores each underlying price source used to compute an aggregated facade price.
 * Evidence trail for mean/median calculations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_position_price_quotes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_position_id');
            $table->unsignedBigInteger('material_price_id');
            $table->unsignedBigInteger('price_list_version_id')
                  ->comment('Denormalized from material_prices for fast joins');
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->decimal('price_per_m2_snapshot', 12, 2);
            $table->dateTime('captured_at');
            $table->timestamps();

            $table->foreign('project_position_id')
                  ->references('id')->on('project_positions')
                  ->onDelete('cascade');

            $table->foreign('material_price_id')
                  ->references('id')->on('material_prices')
                  ->onDelete('cascade');

            $table->foreign('price_list_version_id')
                  ->references('id')->on('price_list_versions')
                  ->onDelete('cascade');

            $table->unique(['project_position_id', 'material_price_id'], 'pppq_position_price_unique');
            $table->index('project_position_id', 'pppq_position_idx');
            $table->index('price_list_version_id', 'pppq_version_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_position_price_quotes');
    }
};
