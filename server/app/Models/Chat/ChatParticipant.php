<?php

namespace App\Models\Chat;

use App\Enums\Chat\ParticipantRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatParticipant extends Model
{
    protected $table = 'chat_participants';

    protected $fillable = [
        'conversation_id',
        'user_id',
        'role',
        'joined_at',
        'left_at',
        'last_read_message_id',
        'last_read_at',
    ];

    protected function casts(): array
    {
        return [
            'role' => ParticipantRole::class,
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'last_read_at' => 'datetime',
        ];
    }

    // ===== Relationships =====

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function user(): BelongsTo
    {
        // withTrashed: user_id is NOT NULL but User uses SoftDeletes,
        // so soft-deleted users must remain reachable through this relation.
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }
}
