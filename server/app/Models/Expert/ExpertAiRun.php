<?php

declare(strict_types=1);

namespace App\Models\Expert;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ExpertAiRun extends Model
{
    protected $fillable = [
        'run_id',
        'user_id',
        'expert_project_id',
        'expert_conversation_id',
        'client_message_id',
        'user_message_id',
        'assistant_message_id',
        'status',
        'stage',
        'requested_mode',
        'resolved_mode',
        'selected_material_count',
        'persisted_material_count',
        'resolved_material_count',
        'active_material_count',
        'provider',
        'model',
        'upstream_provider',
        'upstream_model',
        'started_at',
        'first_token_at',
        'finished_at',
        'duration_ms',
        'finish_reason',
        'error_code',
        'retryable',
        'last_activity_code',
        'metadata',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'expert_project_id' => 'integer',
        'expert_conversation_id' => 'integer',
        'user_message_id' => 'integer',
        'assistant_message_id' => 'integer',
        'selected_material_count' => 'integer',
        'persisted_material_count' => 'integer',
        'resolved_material_count' => 'integer',
        'active_material_count' => 'integer',
        'started_at' => 'datetime',
        'first_token_at' => 'datetime',
        'finished_at' => 'datetime',
        'duration_ms' => 'integer',
        'retryable' => 'boolean',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ExpertProject::class, 'expert_project_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ExpertConversation::class, 'expert_conversation_id');
    }

    public function userMessage(): BelongsTo
    {
        return $this->belongsTo(ExpertMessage::class, 'user_message_id');
    }

    public function assistantMessage(): BelongsTo
    {
        return $this->belongsTo(ExpertMessage::class, 'assistant_message_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ExpertAiRunEvent::class, 'expert_ai_run_id')->orderBy('seq');
    }
}
