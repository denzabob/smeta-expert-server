<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimate_evidence_items', function (Blueprint $table) {
            $table->string('label', 500)->nullable()->after('cost_component');
            $table->decimal('effective_value', 14, 2)->nullable()->after('source_url');
            $table->string('currency', 3)->nullable()->after('effective_value');
        });

        Schema::table('estimate_evidence_runs', function (Blueprint $table) {
            $table->json('snapshot_json')->nullable()->after('metadata_json');
        });
    }

    public function down(): void
    {
        Schema::table('estimate_evidence_items', function (Blueprint $table) {
            $table->dropColumn(['label', 'effective_value', 'currency']);
        });

        Schema::table('estimate_evidence_runs', function (Blueprint $table) {
            $table->dropColumn('snapshot_json');
        });
    }
};
