<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sanctum personal_access_tokens (needed for Chrome ext token auth)
        if (!Schema::hasTable('personal_access_tokens')) {
            Schema::create('personal_access_tokens', function (Blueprint $table) {
                $table->id();
                $table->morphs('tokenable');
                $table->string('name');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('parser_supplier_collect_profiles', function (Blueprint $table) {
            $table->json('url_patterns')->nullable()->after('config_override');
            $table->json('selectors')->nullable()->after('url_patterns');
            $table->json('extraction_rules')->nullable()->after('selectors');
            $table->json('validation_rules')->nullable()->after('extraction_rules');
            $table->json('test_case')->nullable()->after('validation_rules');
            $table->string('source', 20)->default('system')->after('is_default');
            // source: 'system' | 'chrome_ext'
            $table->unsignedInteger('version')->default(1)->after('source');
        });

        // Chrome extension parsing logs
        Schema::create('chrome_ext_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('url', 2048);
            $table->string('domain', 255);
            $table->enum('action', ['capture', 'save_template', 'extract', 'error']);
            $table->enum('status', ['success', 'partial', 'failed']);
            $table->json('extracted_fields')->nullable();
            $table->json('errors')->nullable();
            $table->foreignId('template_id')->nullable()->constrained('parser_supplier_collect_profiles')->onDelete('set null');
            $table->foreignId('material_id')->nullable()->constrained()->onDelete('set null');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['domain', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chrome_ext_logs');

        Schema::table('parser_supplier_collect_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'url_patterns', 'selectors', 'extraction_rules',
                'validation_rules', 'test_case', 'source', 'version',
            ]);
        });

        // Note: not dropping personal_access_tokens as it may be used by other features
    }
};
