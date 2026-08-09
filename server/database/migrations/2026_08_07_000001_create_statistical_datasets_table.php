<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statistical_datasets', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('code', 128)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('provider_code', 64);
            $table->string('provider_name');
            $table->string('data_kind', 64);
            $table->string('frequency', 32);
            $table->string('classifier_code', 64)->nullable();
            $table->string('territory_scope', 64);
            $table->boolean('is_enabled')->default(true);
            $table->boolean('automatic_check_enabled')->default(false);
            $table->string('check_schedule', 64)->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->index(
                ['is_enabled', 'automatic_check_enabled'],
                'stat_datasets_enabled_auto_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statistical_datasets');
    }
};
