<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statistical_classifier_item_mappings', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('statistical_classifier_item_id');
            $table->unsignedBigInteger('classifier_version_id');
            $table->unsignedBigInteger('classifier_node_id')->nullable();
            $table->enum('mapping_type', [
                'exact',
                'parent_aggregate',
                'local_rosstat',
                'ambiguous',
                'unmapped',
            ]);
            $table->enum('review_status', [
                'proposed',
                'needs_review',
                'confirmed',
                'rejected',
            ]);
            $table->string('method', 128);
            $table->decimal('confidence', 5, 4)->nullable();
            $table->json('evidence_json')->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['statistical_classifier_item_id', 'classifier_version_id'],
                'stat_cls_item_mappings_item_version_unique'
            );
            $table->index(
                ['classifier_version_id', 'classifier_node_id', 'review_status'],
                'stat_cls_item_mappings_version_node_review_idx'
            );
            $table->foreign('statistical_classifier_item_id', 'stat_cls_item_mappings_item_fk')
                ->references('id')
                ->on('statistical_classifier_items')
                ->restrictOnDelete();
            $table->foreign('classifier_version_id', 'stat_cls_item_mappings_version_fk')
                ->references('id')
                ->on('statistical_classifier_versions')
                ->restrictOnDelete();
            $table->foreign(
                ['classifier_version_id', 'classifier_node_id'],
                'stat_cls_item_mappings_version_node_fk'
            )
                ->references(['classifier_version_id', 'id'])
                ->on('statistical_classifier_nodes')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statistical_classifier_item_mappings');
    }
};
