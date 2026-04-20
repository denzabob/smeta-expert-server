<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_import_items', function (Blueprint $table) {
            $table->foreignId('operation_id')
                ->nullable()
                ->after('import_id')
                ->constrained('operations')
                ->nullOnDelete();

            $table->string('status', 20)
                ->default('pending')
                ->after('parsed_operation_hint');

            $table->index(['import_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('price_import_items', function (Blueprint $table) {
            $table->dropIndex(['import_id', 'status']);
            $table->dropConstrainedForeignId('operation_id');
            $table->dropColumn('status');
        });
    }
};
