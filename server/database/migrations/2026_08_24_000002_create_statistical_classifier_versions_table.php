<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statistical_classifier_versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('classifier_id')
                ->constrained('statistical_classifiers')
                ->restrictOnDelete();
            $table->string('version_label', 128);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->date('approved_at')->nullable();
            $table->dateTime('source_published_at')->nullable();
            $table->enum('status', ['ready', 'scheduled', 'superseded']);
            $table->unsignedInteger('node_count')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['classifier_id', 'version_label'],
                'stat_cls_versions_classifier_label_unique'
            );
            $table->unique(
                ['classifier_id', 'id'],
                'stat_cls_versions_classifier_id_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statistical_classifier_versions');
    }
};
