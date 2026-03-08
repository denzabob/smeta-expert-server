<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_positions', function (Blueprint $table) {
            if (!Schema::hasColumn('project_positions', 'requires_price_justification')) {
                $table->boolean('requires_price_justification')->default(true)->after('price_max');
                $table->index(['project_id', 'requires_price_justification'], 'project_positions_justification_idx');
            }
        });

        Schema::table('parser_supplier_collect_profiles', function (Blueprint $table) {
            if (!Schema::hasColumn('parser_supplier_collect_profiles', 'visibility')) {
                $table->enum('visibility', ['private', 'public'])->default('private')->after('source');
            }
            if (!Schema::hasColumn('parser_supplier_collect_profiles', 'status')) {
                $table->enum('status', ['active', 'disabled'])->default('active')->after('visibility');
            }
            $table->index(['supplier_name', 'source', 'visibility', 'status'], 'profiles_domain_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::table('project_positions', function (Blueprint $table) {
            if (Schema::hasColumn('project_positions', 'requires_price_justification')) {
                $table->dropIndex('project_positions_justification_idx');
                $table->dropColumn('requires_price_justification');
            }
        });

        Schema::table('parser_supplier_collect_profiles', function (Blueprint $table) {
            $table->dropIndex('profiles_domain_scope_idx');
            if (Schema::hasColumn('parser_supplier_collect_profiles', 'status')) {
                $table->dropColumn('status');
            }
            if (Schema::hasColumn('parser_supplier_collect_profiles', 'visibility')) {
                $table->dropColumn('visibility');
            }
        });
    }
};
