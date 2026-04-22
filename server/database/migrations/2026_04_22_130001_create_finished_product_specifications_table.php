<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finished_product_specifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('product_type', 50);
            $table->string('name');
            $table->string('article')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('facade_class')->nullable();
            $table->string('base_type')->nullable();
            $table->unsignedInteger('thickness_mm')->nullable();
            $table->string('covering')->nullable();
            $table->string('cover_type')->nullable();
            $table->string('collection')->nullable();
            $table->string('decor_label')->nullable();
            $table->string('price_group_label')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'product_type', 'is_active'], 'fp_specs_user_type_active_idx');
            $table->index(['user_id', 'updated_at'], 'fp_specs_user_updated_idx');
            $table->index(['user_id', 'article'], 'fp_specs_user_article_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finished_product_specifications');
    }
};
