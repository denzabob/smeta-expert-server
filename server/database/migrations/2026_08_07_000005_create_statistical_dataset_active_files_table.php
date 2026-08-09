<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statistical_dataset_active_files', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('dataset_id')
                ->constrained('statistical_datasets')
                ->restrictOnDelete();
            $table->unsignedSmallInteger('reporting_year');
            $table->unsignedTinyInteger('reporting_month');
            $table->foreignId('source_file_id')
                ->constrained('statistical_source_files')
                ->restrictOnDelete();
            $table->foreignId('activated_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->dateTime('activated_at');
            $table->timestamps();

            $table->unique(
                ['dataset_id', 'reporting_year', 'reporting_month'],
                'stat_active_dataset_period_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statistical_dataset_active_files');
    }
};
