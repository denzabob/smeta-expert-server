<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('statistical_classifier_versions', function (Blueprint $table) {
            $table->unsignedBigInteger('classifier_import_id')
                ->after('classifier_id');
            $table->unique(
                'classifier_import_id',
                'stat_cls_versions_import_unique'
            );
            $table->foreign(
                ['classifier_id', 'classifier_import_id'],
                'stat_cls_versions_classifier_import_fk'
            )
                ->references(['classifier_id', 'id'])
                ->on('statistical_classifier_imports')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('statistical_classifier_versions', function (Blueprint $table) {
            $table->dropForeign('stat_cls_versions_classifier_import_fk');
            $table->dropUnique('stat_cls_versions_import_unique');
            $table->dropColumn('classifier_import_id');
        });
    }
};
