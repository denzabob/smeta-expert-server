<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step-up authentication challenges.
 *
 * A step-up challenge is created when a user requests a sensitive action.
 * The user must complete one allowed factor (password or phone OTP).
 * On success a short-lived token is issued that authorises the sensitive action.
 *
 * Lifecycle:
 *   pending → completed (via password or phone OTP)
 *   pending → expired   (TTL exceeded or explicitly expired)
 *   pending → failed    (verification exhausted)
 *
 * Token lifecycle:
 *   token is issued on completion, expires after `token_expires_at`.
 *   Token is consumed (token_expires_at set to past) after one use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('step_up_challenges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('scope', 50); // set_quick_pin, change_email, change_phone, set_password, unlink_auth_method, view_security_sessions
            $table->json('allowed_methods'); // e.g. ["password","phone_otp"]
            $table->string('status', 20)->default('pending'); // pending, completed, expired, failed
            $table->string('completed_method', 30)->nullable(); // password, phone_otp
            // Token is a random 64-char string issued after successful challenge completion.
            // Never log or expose this token in audit events.
            $table->string('token', 64)->nullable()->unique();
            $table->timestamp('token_expires_at')->nullable(); // set to 15 min after completion
            // Reference to AuthVerificationChallenge when phone_otp is in use
            $table->uuid('phone_challenge_id')->nullable();
            $table->timestamp('expires_at')->useCurrent(); // verification window (10 min from initiation)
            $table->timestamp('completed_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('token');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('step_up_challenges');
    }
};
