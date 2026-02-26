<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('import_column_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_session_id')->constrained()->onDelete('cascade');
            $table->unsignedInteger('column_index');
            $table->enum('field', ['width', 'length', 'qty', 'ignore'])->nullable();
            $table->timestamps();

            // Unique constraint: each column can only be mapped once per session
            $table->unique(['import_session_id', 'column_index']);
        });

        // Note: The uniqueness of width/length/qty per session is enforced at the 
        // application level in ImportMappingValidator since MySQL doesn't support
        // partial unique indexes like PostgreSQL. The validator ensures that
        // each of width, length, qty can only be assigned to one column per session.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_column_mappings');
    }
};
