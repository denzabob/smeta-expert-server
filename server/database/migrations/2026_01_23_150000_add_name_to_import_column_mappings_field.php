<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE import_column_mappings MODIFY field ENUM('width','length','qty','name','ignore') NULL"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement(
            "ALTER TABLE import_column_mappings MODIFY field ENUM('width','length','qty','ignore') NULL"
        );
    }
};
