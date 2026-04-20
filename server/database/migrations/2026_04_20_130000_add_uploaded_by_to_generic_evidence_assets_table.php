<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generic_evidence_assets', function (Blueprint $table) {
            $table->foreignId('uploaded_by')
                ->nullable()
                ->after('metadata_json')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('generic_evidence_assets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('uploaded_by');
        });
    }
};
