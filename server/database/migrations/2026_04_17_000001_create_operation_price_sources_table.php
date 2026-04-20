<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operation_price_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operation_id')->constrained('operations')->cascadeOnDelete();
            $table->string('type', 20);
            $table->decimal('value', 12, 2);
            $table->string('unit', 40);
            $table->string('source_name')->nullable();
            $table->string('document_ref')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['operation_id', 'is_active'], 'operation_price_sources_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_price_sources');
    }
};
