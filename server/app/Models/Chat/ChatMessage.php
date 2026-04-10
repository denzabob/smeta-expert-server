<?php

namespace App\Models\Chat;

use App\Enums\Chat\MessageType;
use App\Enums\Chat\ParticipantRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChatMessage extends Model
{
    use SoftDeletes;

    protected $table = 'chat_messages';

    protected $fillable = [
        'conversation_id',
        'sender_id',
        'sender_role',
        'type',
        'body',
        'meta_json',
    ];

    protected function casts(): array
    {
        return [
            'sender_role' => ParticipantRole::class,
            'type' => MessageType::class,
            'meta_json' => 'array',
        ];
    }

    // ===== Relationships =====

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id')->withTrashed();
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ChatAttachment::class, 'message_id');
    }
}
