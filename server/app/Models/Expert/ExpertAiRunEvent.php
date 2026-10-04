<?php

declare(strict_types=1);

namespace App\Models\Expert;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ExpertAiRunEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'expert_ai_run_id',
        'run_id',
        'seq',
        'source',
        'level',
        'event_code',
        'stage',
        'status',
        'payload',
        'created_at',
    ];

    protected $casts = [
        'expert_ai_run_id' => 'integer',
        'seq' => 'integer',
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ExpertAiRun::class, 'expert_ai_run_id');
    }
}
