<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_price_histories', function (Blueprint $table) {
            $table->foreignId('evidence_record_id')
                ->nullable()
                ->after('evidence_mode')
                ->constrained('evidence_records')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('material_price_histories', function (Blueprint $table) {
            $table->dropForeign(['evidence_record_id']);
            $table->dropColumn('evidence_record_id');
        });
    }
};
