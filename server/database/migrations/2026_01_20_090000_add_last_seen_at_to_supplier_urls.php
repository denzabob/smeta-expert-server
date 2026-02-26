<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_urls', function (Blueprint $table) {
            if (!Schema::hasColumn('supplier_urls', 'last_seen_at')) {
                $table->timestamp('last_seen_at')->nullable()->after('validated_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('supplier_urls', function (Blueprint $table) {
            if (Schema::hasColumn('supplier_urls', 'last_seen_at')) {
                $table->dropColumn('last_seen_at');
            }
        });
    }
};