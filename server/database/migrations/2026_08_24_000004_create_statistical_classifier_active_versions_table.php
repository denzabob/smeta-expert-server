<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statistical_classifier_active_versions', function (Blueprint $table) {
            $table->unsignedBigInteger('classifier_id');
            $table->unsignedBigInteger('classifier_version_id');
            $table->dateTime('activated_at');
            $table->foreignId('activated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('activation_reason', 512)->nullable();
            $table->timestamps();

            $table->primary('classifier_id', 'stat_cls_active_versions_primary');
            $table->unique('classifier_version_id', 'stat_cls_active_versions_version_unique');
            $table->foreign('classifier_id', 'stat_cls_active_versions_classifier_fk')
                ->references('id')
                ->on('statistical_classifiers')
                ->restrictOnDelete();
            $table->foreign(
                ['classifier_id', 'classifier_version_id'],
                'stat_cls_active_versions_membership_fk'
            )
                ->references(['classifier_id', 'id'])
                ->on('statistical_classifier_versions')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statistical_classifier_active_versions');
    }
};
