<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_import_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained('price_imports')->cascadeOnDelete();
            $table->string('name');
            $table->decimal('value', 12, 2);
            $table->string('unit', 40);
            $table->string('parsed_operation_hint')->nullable();
            $table->timestamps();

            $table->index(['import_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_import_items');
    }
};
