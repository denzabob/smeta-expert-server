<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expert_storage_usages', function (Blueprint $table): void {
            $table->unsignedBigInteger('reserved_bytes')->default(0)->after('originals_bytes');
        });

        Schema::create('expert_storage_upload_reservations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('reservation_id')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('expert_project_id')->nullable()->constrained('expert_projects')->nullOnDelete();
            $table->unsignedBigInteger('requested_bytes');
            $table->string('status', 16)->default('reserved');
            $table->timestamp('expires_at')->index();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'expires_at'], 'expert_storage_reservations_status_expiry_idx');
            $table->index(['user_id', 'status'], 'expert_storage_reservations_user_status_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('expert_storage_upload_reservations')
            && DB::table('expert_storage_upload_reservations')->where('status', 'reserved')->exists()) {
            throw new \RuntimeException('Cannot remove active Expert storage reservations.');
        }
        if (Schema::hasTable('expert_storage_usages')
            && DB::table('expert_storage_usages')->where('reserved_bytes', '>', 0)->exists()) {
            throw new \RuntimeException('Cannot remove non-zero Expert storage reservation accounting.');
        }

        Schema::dropIfExists('expert_storage_upload_reservations');
        Schema::table('expert_storage_usages', function (Blueprint $table): void {
            $table->dropColumn('reserved_bytes');
        });
    }
};
