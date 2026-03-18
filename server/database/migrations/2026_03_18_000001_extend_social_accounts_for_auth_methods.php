<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->string('provider_username')->nullable()->after('provider_user_id');
            $table->timestamp('linked_at')->nullable()->after('provider_phone');
            $table->timestamp('last_used_at')->nullable()->after('linked_at');
            $table->boolean('is_active')->default(true)->after('last_used_at');

            $table->unique(['user_id', 'provider'], 'social_accounts_user_provider_unique');
            $table->index(['user_id', 'is_active'], 'social_accounts_user_active_idx');
        });

        DB::table('social_accounts')->update([
            'linked_at' => DB::raw('created_at'),
            'last_used_at' => DB::raw('updated_at'),
            'is_active' => true,
        ]);
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropIndex('social_accounts_user_active_idx');
            $table->dropUnique('social_accounts_user_provider_unique');
            $table->dropColumn([
                'provider_username',
                'linked_at',
                'last_used_at',
                'is_active',
            ]);
        });
    }
};
