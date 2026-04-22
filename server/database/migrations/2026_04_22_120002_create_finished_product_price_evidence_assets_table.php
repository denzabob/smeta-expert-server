<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finished_product_price_evidence_assets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('finished_product_price_source_id');
            $table->string('asset_type', 30);
            $table->string('file_path')->nullable();
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('source_url', 2000)->nullable();
            $table->string('content_hash', 128)->nullable();
            $table->dateTime('captured_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['finished_product_price_source_id', 'asset_type'], 'fp_price_evidence_source_type_idx');
            $table->foreign('finished_product_price_source_id', 'fp_price_evidence_source_fk')
                ->references('id')
                ->on('finished_product_price_sources')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finished_product_price_evidence_assets');
    }
};
