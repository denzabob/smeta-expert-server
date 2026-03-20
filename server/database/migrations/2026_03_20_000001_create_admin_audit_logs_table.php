<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 50); // view, block, unblock, soft_delete, hard_delete, role_change, etc.
            $table->string('reason', 500)->nullable();
            $table->string('result', 20)->default('success'); // success, error
            $table->json('details')->nullable(); // technical details, error messages, etc.
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['target_user_id', 'created_at']);
            $table->index(['admin_user_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_logs');
    }
};
