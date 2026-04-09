<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add email_challenge_id to step_up_challenges.
 *
 * Enables email OTP as a step-up factor for set_password and other
 * sensitive actions. Analogous to phone_challenge_id which links to
 * an AuthVerificationChallenge record for phone OTP.
 *
 * Block 6A — Email OTP Step-Up
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('step_up_challenges', function (Blueprint $table) {
            $table->uuid('email_challenge_id')->nullable()->after('phone_challenge_id');
        });
    }

    public function down(): void
    {
        Schema::table('step_up_challenges', function (Blueprint $table) {
            $table->dropColumn('email_challenge_id');
        });
    }
};
