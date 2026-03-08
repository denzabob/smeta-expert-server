<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_type_patterns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('priority')->default(100);
            $table->string('material_type', 32);
            $table->string('source', 64)->nullable();
            $table->string('rule_type', 32)->default('regex');
            $table->string('target_field', 32)->default('title');
            $table->text('pattern');
            $table->string('flags', 16)->default('iu');
            $table->boolean('use_normalized_text')->default(true);
            $table->string('example_input', 1024)->nullable();
            $table->string('expected_material_type', 32)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'priority']);
            $table->index(['source', 'target_field']);
            $table->index(['material_type', 'is_active']);
        });

        DB::table('material_type_patterns')->insert([
            [
                'name' => 'Edge by title keyword',
                'description' => 'Detect edge materials when title contains кромка.',
                'is_active' => true,
                'priority' => 10,
                'material_type' => 'edge',
                'source' => null,
                'rule_type' => 'regex',
                'target_field' => 'title',
                'pattern' => '\\bкромк[а-яa-z0-9_-]*\\b',
                'flags' => 'iu',
                'use_normalized_text' => true,
                'example_input' => 'Кромка ПВХ 0.4x19 мм белая',
                'expected_material_type' => 'edge',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Edge by URL marker',
                'description' => 'Detect edge materials when URL contains kromka.',
                'is_active' => true,
                'priority' => 20,
                'material_type' => 'edge',
                'source' => null,
                'rule_type' => 'regex',
                'target_field' => 'url',
                'pattern' => 'kromka',
                'flags' => 'iu',
                'use_normalized_text' => true,
                'example_input' => 'https://site.ru/catalog/kromka/pvh-19mm',
                'expected_material_type' => 'edge',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Plate by sheet keyword',
                'description' => 'Detect plate materials by common board keywords in title.',
                'is_active' => true,
                'priority' => 50,
                'material_type' => 'plate',
                'source' => null,
                'rule_type' => 'regex',
                'target_field' => 'title',
                'pattern' => '\\b(лдсп|мдф|хдф|осб|лмдф|osb|двпо|дсп|двп|лхдф|лосб|hpl|cpl|фсф|фк)\\b',
                'flags' => 'iu',
                'use_normalized_text' => true,
                'example_input' => 'ЛДСП Egger 16мм дуб галифакс',
                'expected_material_type' => 'plate',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('material_type_patterns');
    }
};
