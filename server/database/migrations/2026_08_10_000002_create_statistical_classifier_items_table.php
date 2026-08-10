<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statistical_classifier_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('dataset_id')
                ->constrained('statistical_datasets')
                ->restrictOnDelete();
            $table->string('classifier_code', 64);
            $table->string('item_code', 128);
            $table->string('name', 512);
            $table->string('normalized_name', 512);
            $table->foreignId('parent_item_id')
                ->nullable()
                ->constrained('statistical_classifier_items')
                ->restrictOnDelete();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->unique(
                ['dataset_id', 'classifier_code', 'item_code'],
                'stat_classifier_dataset_classifier_item_unique'
            );
            $table->index('item_code', 'stat_classifier_item_code_idx');
            $table->index(
                ['dataset_id', 'normalized_name'],
                'stat_classifier_dataset_normalized_name_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statistical_classifier_items');
    }
};
