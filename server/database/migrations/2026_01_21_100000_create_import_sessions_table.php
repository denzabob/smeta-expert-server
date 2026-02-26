<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('import_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('project_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('file_path');
            $table->string('storage_disk')->default('local');
            $table->string('original_filename');
            $table->enum('file_type', ['xlsx', 'xls', 'csv']);
            $table->enum('status', ['uploaded', 'mapped', 'imported', 'failed'])->default('uploaded');
            $table->unsignedInteger('header_row_index')->default(0)->comment('0-based index');
            $table->unsignedInteger('sheet_index')->default(0)->comment('0-based index for xlsx');
            $table->json('options')->nullable()->comment('csv_encoding, csv_delimiter, units_length, default_qty_if_empty, etc.');
            $table->json('result')->nullable()->comment('created_count, skipped_count, errors array');
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['project_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_sessions');
    }
};
