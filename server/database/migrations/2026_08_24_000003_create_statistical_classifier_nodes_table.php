<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statistical_classifier_nodes', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('classifier_version_id')
                ->constrained('statistical_classifier_versions')
                ->restrictOnDelete();
            $table->string('code', 128);
            $table->string('name', 512);
            $table->string('normalized_name', 512);
            $table->enum('semantic_level', [
                'section',
                'class',
                'subclass',
                'group',
                'subgroup',
                'type',
                'category',
                'subcategory',
            ]);
            $table->unsignedTinyInteger('formal_depth');
            $table->unsignedBigInteger('parent_node_id')->nullable();
            $table->unsignedInteger('source_order')->nullable();
            $table->text('notes_text')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->unique(
                ['classifier_version_id', 'code'],
                'stat_cls_nodes_version_code_unique'
            );
            $table->unique(
                ['classifier_version_id', 'id'],
                'stat_cls_nodes_version_id_unique'
            );
            $table->index(
                ['classifier_version_id', 'parent_node_id', 'source_order'],
                'stat_cls_nodes_parent_order_idx'
            );
            $table->foreign(
                ['classifier_version_id', 'parent_node_id'],
                'stat_cls_nodes_version_parent_fk'
            )
                ->references(['classifier_version_id', 'id'])
                ->on('statistical_classifier_nodes')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statistical_classifier_nodes');
    }
};
