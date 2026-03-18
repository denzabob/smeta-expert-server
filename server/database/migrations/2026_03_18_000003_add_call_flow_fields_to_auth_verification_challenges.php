<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auth_verification_challenges', function (Blueprint $table) {
            $table->string('call_phone', 20)->nullable()->after('provider_message_id');
            $table->string('call_phone_pretty', 40)->nullable()->after('call_phone');
            $table->timestamp('verified_at')->nullable()->after('status');
            $table->json('provider_payload')->nullable()->after('last_error');

            $table->index('provider_message_id', 'auth_verification_challenges_provider_msg_idx');
        });
    }

    public function down(): void
    {
        Schema::table('auth_verification_challenges', function (Blueprint $table) {
            $table->dropIndex('auth_verification_challenges_provider_msg_idx');
            $table->dropColumn([
                'call_phone',
                'call_phone_pretty',
                'verified_at',
                'provider_payload',
            ]);
        });
    }
};
