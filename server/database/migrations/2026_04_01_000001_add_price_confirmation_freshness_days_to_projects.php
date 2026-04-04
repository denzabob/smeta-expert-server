<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedSmallInteger('price_confirmation_freshness_days')
                ->nullable()
                ->default(7)
                ->after('normohour_justification')
                ->comment('Срок актуальности подтверждения цены материала, дней');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('price_confirmation_freshness_days');
        });
    }
};
