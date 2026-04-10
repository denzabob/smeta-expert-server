<?php

declare(strict_types=1);

namespace App\Models\Chat;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ChatAttachment extends Model
{
    protected $table = 'chat_attachments';

    protected $fillable = [
        'message_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'width',
        'height',
    ];

    protected function casts(): array
    {
        return [
            'size'   => 'integer',
            'width'  => 'integer',
            'height' => 'integer',
        ];
    }

    // ===== Relationships =====

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }

    // ===== Helpers =====

    /**
     * Whether the attachment is an image type that can be displayed inline.
     */
    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    /**
     * Delete the underlying file from storage when the model is deleted.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $attachment): void {
            Storage::disk($attachment->disk)->delete($attachment->path);
        });
    }
}
