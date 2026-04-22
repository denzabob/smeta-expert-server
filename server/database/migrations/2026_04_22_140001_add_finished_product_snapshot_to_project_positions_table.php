<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_positions', function (Blueprint $table) {
            $table->foreignId('finished_product_specification_id')
                ->nullable()
                ->after('facade_material_id')
                ->constrained('finished_product_specifications')
                ->nullOnDelete();

            $table->json('finished_product_pricing_snapshot')
                ->nullable()
                ->after('price_max');
        });
    }

    public function down(): void
    {
        Schema::table('project_positions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('finished_product_specification_id');
            $table->dropColumn('finished_product_pricing_snapshot');
        });
    }
};
