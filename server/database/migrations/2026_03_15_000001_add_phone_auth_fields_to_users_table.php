<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('full_name')->nullable()->after('name');
            $table->string('phone', 20)->nullable()->unique()->after('email');
            $table->timestamp('phone_verified_at')->nullable()->after('phone');
            $table->timestamp('email_verified_at')->nullable()->change();
            $table->string('activity_profile')->nullable()->after('phone_verified_at');
            $table->timestamp('registration_completed_at')->nullable()->after('activity_profile');
            $table->string('last_login_channel', 30)->nullable()->after('registration_completed_at');
            $table->string('auth_status', 20)->default('active')->after('last_login_channel');
        });

        // Make email nullable for phone-first users
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });

        // Make password nullable for phone/oauth-only users
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'full_name',
                'phone',
                'phone_verified_at',
                'activity_profile',
                'registration_completed_at',
                'last_login_channel',
                'auth_status',
            ]);
        });
    }
};
