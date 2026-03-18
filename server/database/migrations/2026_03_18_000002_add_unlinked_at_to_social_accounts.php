<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->timestamp('unlinked_at')->nullable()->after('is_active');
            $table->index(['provider', 'provider_user_id', 'is_active'], 'social_accounts_provider_identity_active_idx');
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropIndex('social_accounts_provider_identity_active_idx');
            $table->dropColumn('unlinked_at');
        });
    }
};
