<?php

declare(strict_types=1);

namespace App\Models\Expert;

use Illuminate\Database\Eloquent\Model;

final class ExpertStorageMigration extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_COPYING = 'copying';
    public const STATUS_COPIED = 'copied';
    public const STATUS_VERIFYING = 'verifying';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_SWITCHED = 'switched';
    public const STATUS_CLEANUP_PENDING = 'cleanup_pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ROLLED_BACK = 'rolled_back';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'source_size' => 'integer',
            'target_size' => 'integer',
            'target_created' => 'boolean',
            'attempts' => 'integer',
            'started_at' => 'datetime',
            'copied_at' => 'datetime',
            'verified_at' => 'datetime',
            'switched_at' => 'datetime',
            'cleanup_after' => 'datetime',
            'cleaned_at' => 'datetime',
            'rolled_back_at' => 'datetime',
        ];
    }

    public function material()
    {
        return $this->belongsTo(ExpertProjectMaterial::class, 'material_id');
    }
}
