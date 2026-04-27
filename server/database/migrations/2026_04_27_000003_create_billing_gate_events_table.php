<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_gate_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('plan_code')->nullable()->index();
            $table->string('capability')->index();
            $table->integer('limit_value')->nullable();
            $table->integer('usage_value')->default(0);
            $table->boolean('would_block')->default(false)->index();
            $table->boolean('enforced')->default(false)->index();
            $table->json('context_json')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['capability', 'created_at']);
            $table->index(['would_block', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_gate_events');
    }
};
