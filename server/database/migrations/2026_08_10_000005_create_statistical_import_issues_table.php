<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statistical_import_issues', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('import_id')
                ->constrained('statistical_imports')
                ->restrictOnDelete();
            $table->string('severity', 32);
            $table->string('code', 128);
            $table->text('message');
            $table->string('sheet_name')->nullable();
            $table->unsignedInteger('source_row')->nullable();
            $table->string('source_column', 16)->nullable();
            $table->string('classifier_item_code', 128)->nullable();
            $table->json('details_json')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['import_id', 'severity'], 'stat_import_issues_import_severity_idx');
            $table->index('code', 'stat_import_issues_code_idx');
            $table->index(['import_id', 'created_at'], 'stat_import_issues_import_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statistical_import_issues');
    }
};
