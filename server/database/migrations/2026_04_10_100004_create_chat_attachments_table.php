<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attachments for chat messages.
 *
 * One message can have many attachments (hasMany).
 * Files are stored on the 'local' disk (private) and served via a
 * proxied auth-guarded route — raw paths are never exposed to clients.
 *
 * MVP: only image/* types are accepted. The table is designed to be
 * extended for other file types without schema changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_attachments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('message_id')
                ->constrained('chat_messages')
                ->cascadeOnDelete();

            // Which storage disk holds the file.
            $table->string('disk', 20)->default('local');

            // Relative path inside the disk root.
            $table->text('path');

            // Original file name provided by the client (display only).
            $table->string('original_name', 255);

            // MIME type validated server-side — never trust client headers.
            $table->string('mime_type', 100);

            // File size in bytes.
            $table->unsignedInteger('size');

            // Optional image dimensions (null for non-image types).
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();

            $table->timestamps();

            $table->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_attachments');
    }
};
