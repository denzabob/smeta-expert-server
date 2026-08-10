<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statistical_territories', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('normalized_name');
            $table->string('type', 64);
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('statistical_territories')
                ->restrictOnDelete();
            $table->string('provider_code', 128)->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->index(['parent_id', 'type'], 'stat_territories_parent_type_idx');
            $table->index('normalized_name', 'stat_territories_normalized_name_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statistical_territories');
    }
};
