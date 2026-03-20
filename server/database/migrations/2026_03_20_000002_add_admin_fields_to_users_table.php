<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add soft delete support and role field to users table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->softDeletes();
            $table->string('role', 30)->default('user')->after('auth_status');
            $table->string('blocked_reason', 500)->nullable()->after('role');
            $table->foreignId('blocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('blocked_at')->nullable();
            $table->timestamp('last_login_at')->nullable();

            $table->index('auth_status');
            $table->index('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropForeign(['blocked_by']);
            $table->dropColumn(['role', 'blocked_reason', 'blocked_by', 'blocked_at', 'last_login_at']);
        });
    }
};
