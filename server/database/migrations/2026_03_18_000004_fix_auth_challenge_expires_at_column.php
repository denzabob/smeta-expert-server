<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Keep challenge timestamps as plain datetime values and prevent implicit ON UPDATE behavior.
        DB::statement('ALTER TABLE auth_verification_challenges MODIFY expires_at DATETIME NOT NULL');
        DB::statement('ALTER TABLE auth_verification_challenges MODIFY resend_available_at DATETIME NULL');
        DB::statement('ALTER TABLE auth_verification_challenges MODIFY verified_at DATETIME NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE auth_verification_challenges MODIFY expires_at TIMESTAMP NOT NULL');
        DB::statement('ALTER TABLE auth_verification_challenges MODIFY resend_available_at TIMESTAMP NULL');
        DB::statement('ALTER TABLE auth_verification_challenges MODIFY verified_at TIMESTAMP NULL');
    }
};
