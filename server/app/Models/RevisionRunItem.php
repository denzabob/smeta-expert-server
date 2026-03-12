<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RevisionRunItem extends Model
{
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_OK = 'OK';
    public const STATUS_BLOCKED = 'BLOCKED';
    public const STATUS_TIMEOUT = 'TIMEOUT';
    public const STATUS_PARSE_ERROR = 'PARSE_ERROR';
    public const STATUS_NO_TEMPLATE = 'NO_TEMPLATE';
    public const STATUS_NEEDS_MANUAL = 'NEEDS_MANUAL';
    public const STATUS_OK_NO_PRICE = 'OK_NO_PRICE';

    public const STATE_PENDING = 'pending';
    public const STATE_RUNNING = 'running';
    public const STATE_FAILED = 'failed';
    public const STATE_MANUAL_REQUIRED = 'manual_required';
    public const STATE_MANUAL_VERIFIED = 'manual_verified';
    public const STATE_AUTO_VERIFIED = 'auto_verified';
    public const STATE_FINALIZED = 'finalized';

    protected $fillable = [
        'revision_run_id',
        'project_position_id',
        'material_id',
        'source_url',
        'status',
        'state',
        'stage',
        'reason_code',
        'attempt_count',
        'last_error_at',
        'diagnostics_json',
        'message',
        'price_history_id',
    ];

    protected $casts = [
        'attempt_count' => 'integer',
        'last_error_at' => 'datetime',
        'diagnostics_json' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(RevisionRun::class, 'revision_run_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(ProjectPosition::class, 'project_position_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function priceHistory(): BelongsTo
    {
        return $this->belongsTo(MaterialPriceHistory::class, 'price_history_id');
    }

    public function evidenceArtifacts(): HasMany
    {
        return $this->hasMany(EvidenceArtifact::class, 'revision_run_item_id');
    }

    /**
     * Whether this item completed successfully (either legacy OK or pipeline auto_verified).
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_OK
            || $this->state === self::STATE_AUTO_VERIFIED
            || $this->state === self::STATE_MANUAL_VERIFIED
            || $this->state === self::STATE_FINALIZED;
    }
}
