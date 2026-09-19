<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expert_message_materials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('expert_message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expert_project_material_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('position');
            $table->uuid('material_public_id_snapshot');
            $table->string('original_name_snapshot');
            $table->string('mime_type_snapshot', 128);
            $table->unsignedBigInteger('size_snapshot');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['expert_message_id', 'position']);
            $table->unique(['expert_message_id', 'material_public_id_snapshot'], 'expert_message_material_public_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expert_message_materials');
    }
};
