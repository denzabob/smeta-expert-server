<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revision_publication_views', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('revision_publication_id');
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('viewed_at')->useCurrent();
            $table->timestamps();

            $table->index(['revision_publication_id', 'viewed_at'], 'rev_pub_views_pubid_viewed_at_idx');
            $table->foreign('revision_publication_id')->references('id')->on('revision_publications')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revision_publication_views');
    }
};
