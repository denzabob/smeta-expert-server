<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Обходим ENUM, приводя к строке
        DB::statement("
            UPDATE parsing_sessions 
            SET status = 'stopped' 
            WHERE CAST(status AS CHAR) NOT IN ('pending', 'running', 'completed', 'failed', 'stopped')
               OR status = ''
        ");

        // Теперь безопасно меняем ENUM
        DB::statement("
            ALTER TABLE parsing_sessions 
            MODIFY COLUMN status ENUM('pending', 'running', 'completed', 'failed', 'stopped', 'canceling') 
            NOT NULL DEFAULT 'pending'
        ");
    }

    public function down(): void
    {
        DB::table('parsing_sessions')
            ->where('status', 'canceling')
            ->update(['status' => 'stopped']);

        DB::statement("
            ALTER TABLE parsing_sessions 
            MODIFY COLUMN status ENUM('pending', 'running', 'completed', 'failed', 'stopped') 
            NOT NULL DEFAULT 'pending'
        ");
    }
};