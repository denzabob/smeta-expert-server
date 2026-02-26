<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parser_supplier_collect_profiles', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->after('id');

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['supplier_name', 'user_id'], 'pscp_supplier_user_idx');
        });
    }

    public function down(): void
    {
        Schema::table('parser_supplier_collect_profiles', function (Blueprint $table) {
            $table->dropIndex('pscp_supplier_user_idx');
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
