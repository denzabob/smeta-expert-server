<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statistical_indicators', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('dataset_id')
                ->constrained('statistical_datasets')
                ->restrictOnDelete();
            $table->string('code', 128);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('data_kind', 64);
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->unique(['dataset_id', 'code'], 'stat_indicators_dataset_code_unique');
            $table->index('data_kind', 'stat_indicators_data_kind_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statistical_indicators');
    }
};
