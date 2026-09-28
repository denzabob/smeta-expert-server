<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('storage_files', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            // A hard-deleted account retains only deleting/deleted cleanup
            // tombstones, so physical retry locators survive its deletion.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('module', 32);
            $table->string('purpose', 100);
            $table->string('disk', 32);
            $path = $table->string('path', 512);
            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $path->collation('utf8mb4_bin');
            }
            $table->string('original_filename')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->string('category', 16);
            $table->boolean('billable')->default(true);
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['disk', 'path'], 'storage_files_physical_unique');
            $table->index(['user_id', 'module', 'category', 'status'], 'storage_files_usage_index');
            $table->index('module');
            $table->index('category');
            $table->index('status');
        });
        Schema::create('storage_file_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('storage_file_id')->constrained('storage_files')->cascadeOnDelete();
            $table->string('module', 32);
            $table->string('source_type', 100);
            $table->string('source_id', 64);
            $table->timestamp('created_at');
            $table->unique(['storage_file_id', 'module', 'source_type', 'source_id'], 'storage_links_unique');
            $table->index(['module', 'source_type', 'source_id'], 'storage_links_source_index');
        });
        Schema::create('storage_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $this->counters($table, true);
            $table->timestamps();
        });
        Schema::create('storage_usage_modules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('module', 32);
            $this->counters($table);
            $table->timestamps();
            $table->unique(['user_id', 'module']);
        });
        Schema::create('storage_upload_reservations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('reservation_id')->unique();
            $table->foreignId('storage_file_id')->nullable()->constrained('storage_files')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('module', 32);
            $table->unsignedBigInteger('requested_bytes');
            $table->unsignedBigInteger('actual_bytes')->nullable();
            $table->string('status', 16)->default('reserved');
            $table->timestamp('expires_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index(['status', 'expires_at']);
        });
    }

    private function counters(Blueprint $table, bool $reserved = false): void
    {
        foreach (['used_bytes', 'files_count', 'images_bytes', 'images_count'] as $column) {
            $table->unsignedBigInteger($column)->default(0);
        }
        if ($reserved) {
            $table->unsignedBigInteger('reserved_bytes')->default(0);
        }
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('storage_files')->exists()
            || \Illuminate\Support\Facades\DB::table('storage_upload_reservations')->where('status', 'reserved')->exists()) {
            throw new RuntimeException('Account storage contains files or active reservations; rollback refused.');
        }
        foreach (['storage_file_links', 'storage_upload_reservations', 'storage_usage_modules', 'storage_usages', 'storage_files'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
