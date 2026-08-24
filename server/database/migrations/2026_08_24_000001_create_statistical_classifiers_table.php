<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statistical_classifiers', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('code', 64)->unique('stat_classifiers_code_unique');
            $table->string('standard_code', 128);
            $table->string('name', 512);
            $table->string('issuing_authority', 255);
            $table->string('responsible_body', 255)->nullable();
            $table->string('official_distributor', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statistical_classifiers');
    }
};
