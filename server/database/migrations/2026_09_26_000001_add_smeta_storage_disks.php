<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'price_imports' => 'file_path',
            'evidence_artifacts' => 'screenshot_path',
            'evidence_assets' => 'file_path',
            'generic_evidence_assets' => 'file_path',
            'finished_product_price_evidence_assets' => 'file_path',
            'material_price_histories' => 'screenshot_path',
            'materials' => 'last_price_screenshot_path',
        ] as $tableName => $after) {
            if (Schema::hasTable($tableName) && !Schema::hasColumn($tableName, 'storage_disk')) {
                Schema::table($tableName, function (Blueprint $table) use ($after) {
                    $table->string('storage_disk', 32)->nullable()->after($after);
                });
            }
        }
    }

    public function down(): void
    {
        foreach ([
            'price_imports',
            'evidence_artifacts',
            'evidence_assets',
            'generic_evidence_assets',
            'finished_product_price_evidence_assets',
            'material_price_histories',
            'materials',
        ] as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'storage_disk')) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('storage_disk'));
            }
        }
    }
};
