<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->json('features_json')->nullable();
            $table->json('limits_json')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::create('billing_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('billing_plans')->nullOnDelete();
            $table->string('plan_code')->nullable();
            $table->string('status');
            $table->string('source')->default('hidden');
            $table->dateTime('current_period_start')->nullable();
            $table->dateTime('current_period_end')->nullable();
            $table->dateTime('trial_ends_at')->nullable();
            $table->json('overrides_json')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('plan_id');
            $table->index('plan_code');
            $table->index('status');
        });

        Schema::create('feature_entitlements', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 40)->default('user');
            $table->unsignedBigInteger('owner_id');
            $table->string('feature_code', 100);
            $table->boolean('enabled')->default(true);
            $table->string('source', 40)->default('plan');
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
            $table->index('feature_code');
            $table->index('starts_at');
            $table->index('ends_at');
        });

        Schema::create('usage_events', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 40)->default('user');
            $table->unsignedBigInteger('owner_id');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('metric_code', 100);
            $table->string('feature_code', 100)->nullable();
            $table->decimal('quantity', 20, 4)->default(1);
            $table->string('unit', 40)->nullable();
            $table->string('subject_type', 120)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('request_id', 120)->nullable();
            $table->string('idempotency_key', 120)->nullable();
            $table->string('source', 40)->nullable();
            $table->json('metadata_json')->nullable();
            $table->dateTime('occurred_at');
            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
            $table->index('user_id');
            $table->index('project_id');
            $table->index('metric_code');
            $table->index('feature_code');
            $table->index('occurred_at');
            $table->index('idempotency_key');
        });

        Schema::create('usage_counters', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 40)->default('user');
            $table->unsignedBigInteger('owner_id');
            $table->string('metric_code', 100);
            $table->dateTime('period_start');
            $table->dateTime('period_end');
            $table->decimal('quantity', 20, 4)->default(0);
            $table->json('limit_snapshot')->nullable();
            $table->timestamps();

            $table->unique(
                ['owner_type', 'owner_id', 'metric_code', 'period_start', 'period_end'],
                'usage_counters_owner_metric_period_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_counters');
        Schema::dropIfExists('usage_events');
        Schema::dropIfExists('feature_entitlements');
        Schema::dropIfExists('billing_subscriptions');
        Schema::dropIfExists('billing_plans');
    }
};
