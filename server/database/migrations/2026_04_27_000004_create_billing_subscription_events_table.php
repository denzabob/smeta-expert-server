<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_subscription_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('billing_subscriptions')->nullOnDelete();
            $table->string('event_type')->index();
            $table->string('old_plan_code')->nullable();
            $table->string('new_plan_code')->nullable();
            $table->string('old_status')->nullable();
            $table->string('new_status')->nullable();
            $table->dateTime('old_period_end')->nullable();
            $table->dateTime('new_period_end')->nullable();
            $table->text('reason')->nullable();
            $table->json('context_json')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('user_id');
            $table->index('admin_user_id');
            $table->index('subscription_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_subscription_events');
    }
};
