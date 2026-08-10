<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statistical_dataset_active_imports', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('dataset_id')
                ->constrained('statistical_datasets')
                ->restrictOnDelete();
            $table->foreignId('import_id')
                ->constrained('statistical_imports')
                ->restrictOnDelete();
            $table->foreignId('published_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->dateTime('published_at');
            $table->timestamps();

            $table->unique('dataset_id', 'stat_active_imports_dataset_unique');
            $table->unique('import_id', 'stat_active_imports_import_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statistical_dataset_active_imports');
    }
};
