<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statistical_series', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('dataset_id')
                ->constrained('statistical_datasets')
                ->restrictOnDelete();
            $table->foreignId('indicator_id')
                ->constrained('statistical_indicators')
                ->restrictOnDelete();
            $table->foreignId('classifier_item_id')
                ->constrained('statistical_classifier_items')
                ->restrictOnDelete();
            $table->foreignId('territory_id')
                ->constrained('statistical_territories')
                ->restrictOnDelete();
            $table->string('frequency', 32);
            $table->string('comparison_basis', 64);
            $table->string('unit', 32);
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->unique(
                [
                    'dataset_id',
                    'indicator_id',
                    'classifier_item_id',
                    'territory_id',
                    'frequency',
                    'comparison_basis',
                    'unit',
                ],
                'stat_series_dimensions_unique'
            );
            $table->index(
                ['dataset_id', 'frequency', 'comparison_basis'],
                'stat_series_dataset_frequency_basis_idx'
            );
            $table->index(
                ['dataset_id', 'classifier_item_id'],
                'stat_series_dataset_classifier_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statistical_series');
    }
};
