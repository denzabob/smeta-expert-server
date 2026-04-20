<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('labor_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->string('domain')->nullable();
            $table->string('base_url', 2048)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
            $table->index(['user_id', 'is_active']);
            $table->index(['user_id', 'sort_order']);
        });

        Schema::create('labor_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
            $table->index(['user_id', 'is_active']);
            $table->index(['user_id', 'sort_order']);
        });

        Schema::create('labor_evidence_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('region_id')->constrained('regions')->restrictOnDelete();
            $table->foreignId('labor_profile_id')->constrained('labor_profiles')->restrictOnDelete();
            $table->foreignId('provider_id')->constrained('labor_providers')->restrictOnDelete();
            $table->foreignId('evidence_record_id')->nullable()->unique()->constrained('evidence_records')->nullOnDelete();

            $table->string('source_title')->nullable();
            $table->text('source_url');
            $table->date('source_date')->nullable();

            $table->string('employer_name')->nullable();
            $table->string('vacancy_title')->nullable();
            $table->longText('vacancy_description')->nullable();
            $table->text('vacancy_excerpt')->nullable();

            $table->text('salary_raw_text')->nullable();
            $table->decimal('salary_value', 12, 2)->nullable();
            $table->decimal('salary_value_min', 12, 2)->nullable();
            $table->decimal('salary_value_max', 12, 2)->nullable();
            $table->enum('salary_period', ['hour', 'day', 'month', 'year', 'project'])->nullable();

            $table->unsignedInteger('hours_per_month')->default(160);
            $table->decimal('derived_hourly_rate', 12, 2)->nullable();

            $table->string('currency', 10)->default('RUB');
            $table->text('note')->nullable();
            $table->enum('captured_via', ['manual', 'chrome', 'import'])->default('manual');
            $table->enum('verification_status', ['pending', 'verified', 'rejected'])->default('pending');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
            $table->index('region_id');
            $table->index('labor_profile_id');
            $table->index('provider_id');
            $table->index(['user_id', 'is_active']);
            $table->index(['user_id', 'region_id']);
            $table->index(['user_id', 'labor_profile_id']);
            $table->index(['user_id', 'provider_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('labor_evidence_sources');
        Schema::dropIfExists('labor_profiles');
        Schema::dropIfExists('labor_providers');
    }
};
