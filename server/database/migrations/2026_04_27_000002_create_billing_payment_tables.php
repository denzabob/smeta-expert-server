<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('billing_subscriptions')->nullOnDelete();
            $table->string('plan_code');
            $table->dateTime('period_start')->nullable();
            $table->dateTime('period_end')->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3)->default('RUB');
            $table->string('status');
            $table->text('description')->nullable();
            $table->json('metadata_json')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('canceled_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('subscription_id');
            $table->index('plan_code');
            $table->index('status');
        });

        Schema::create('billing_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('invoice_id')->constrained('billing_invoices')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider_code');
            $table->string('provider_payment_id')->nullable();
            $table->string('idempotency_key')->unique();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3)->default('RUB');
            $table->string('status');
            $table->string('confirmation_type')->nullable();
            $table->text('confirmation_url')->nullable();
            $table->text('confirmation_token')->nullable();
            $table->json('provider_payload')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->dateTime('succeeded_at')->nullable();
            $table->dateTime('canceled_at')->nullable();
            $table->timestamps();

            $table->index('invoice_id');
            $table->index('user_id');
            $table->index('provider_code');
            $table->index('status');
            $table->unique(['provider_code', 'provider_payment_id'], 'billing_payments_provider_payment_unique');
        });

        Schema::create('billing_provider_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider_code');
            $table->string('event_type');
            $table->string('provider_object_id')->nullable();
            $table->string('provider_payment_id')->nullable();
            $table->json('payload');
            $table->json('headers')->nullable();
            $table->string('processing_status');
            $table->text('processing_error')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->timestamps();

            $table->index('provider_code');
            $table->index('event_type');
            $table->index('provider_object_id');
            $table->index('provider_payment_id');
            $table->index('processing_status');
            $table->index('created_at');
            $table->index(
                ['provider_code', 'event_type', 'provider_object_id'],
                'billing_provider_events_provider_type_object_idx'
            );
        });

        Schema::create('billing_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider_code');
            $table->string('provider_payment_method_id');
            $table->string('status');
            $table->string('title')->nullable();
            $table->string('card_last4', 4)->nullable();
            $table->string('card_type')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->json('provider_payload')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('provider_code');
            $table->index('provider_payment_method_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_payment_methods');
        Schema::dropIfExists('billing_provider_events');
        Schema::dropIfExists('billing_payments');
        Schema::dropIfExists('billing_invoices');
    }
};
