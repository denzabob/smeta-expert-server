<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('pin_enabled')->default(false)->after('password');
            $table->string('pin_hash', 255)->nullable()->after('pin_enabled');
            $table->timestamp('pin_changed_at')->nullable()->after('pin_hash');
            $table->unsignedTinyInteger('pin_attempts')->default(0)->after('pin_changed_at');
            $table->timestamp('pin_locked_until')->nullable()->after('pin_attempts');
            $table->string('current_session_id', 255)->nullable()->after('pin_locked_until');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'pin_enabled',
                'pin_hash',
                'pin_changed_at',
                'pin_attempts',
                'pin_locked_until',
                'current_session_id',
            ]);
        });
    }
};
