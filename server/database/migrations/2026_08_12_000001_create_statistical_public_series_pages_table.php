<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statistical_public_series_pages', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('dataset_id')->constrained('statistical_datasets')->restrictOnDelete();
            $table->foreignId('import_id')->constrained('statistical_imports')->restrictOnDelete();
            $table->foreignId('series_id')->unique()->constrained('statistical_series')->restrictOnDelete();
            $table->foreignId('classifier_item_id')->constrained('statistical_classifier_items')->restrictOnDelete();
            $table->foreignId('source_file_id')->constrained('statistical_source_files')->restrictOnDelete();
            $table->string('slug', 191)->nullable()->unique();
            $table->boolean('is_indexable')->default(false)->index();
            $table->string('indexability_status', 64);
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->unsignedInteger('observations_count')->default(0);
            $table->unsignedInteger('factors_count')->default(0);
            $table->decimal('coefficient_raw', 38, 20)->nullable();
            $table->decimal('coefficient', 38, 12)->nullable();
            $table->decimal('change_percent_raw', 38, 20)->nullable();
            $table->decimal('change_percent', 38, 2)->nullable();
            $table->decimal('min_index_value', 20, 10)->nullable();
            $table->date('min_index_period')->nullable();
            $table->decimal('max_index_value', 20, 10)->nullable();
            $table->date('max_index_period')->nullable();
            $table->dateTime('generated_at');
            $table->dateTime('source_published_at')->nullable();
            $table->timestamps();

            $table->index(['dataset_id', 'is_indexable'], 'stat_public_pages_dataset_indexable_idx');
            $table->index('import_id', 'stat_public_pages_import_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statistical_public_series_pages');
    }
};
