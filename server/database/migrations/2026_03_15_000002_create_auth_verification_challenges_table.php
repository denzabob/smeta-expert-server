<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_verification_challenges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('purpose', 30); // signup, login, email_verify, recovery
            $table->string('phone', 20)->nullable()->index();
            $table->string('email')->nullable();
            $table->string('code_hash');
            $table->timestamp('expires_at');
            $table->unsignedTinyInteger('attempts_left')->default(5);
            $table->timestamp('resend_available_at')->nullable();
            $table->string('status', 20)->default('pending'); // pending, verified, expired, failed, canceled
            $table->string('current_channel', 30)->nullable(); // telegram_gateway, sms_ru, email
            $table->json('channel_attempt_order')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->text('last_error')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['phone', 'purpose', 'status']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_verification_challenges');
    }
};
