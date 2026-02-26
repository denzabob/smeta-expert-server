<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Carbon\Carbon;

class ProjectRevision extends Model
{
    use HasUuids;

    protected $table = 'project_revisions';

    /**
     * The primary key type
     */
    protected $keyType = 'string';

    /**
     * Do not auto-increment the primary key
     */
    public $incrementing = false;

    /**
     * Mass assignable attributes
     */
    protected $fillable = [
        'project_id',
        'created_by_user_id',
        'number',
        'status',
        'snapshot_json',
        'snapshot_hash',
        'app_version',
        'calculation_engine_version',
        'locked_at',
        'published_at',
        'stale_at',
    ];

    /**
     * Casts
     */
    protected $casts = [
        'snapshot_json' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'locked_at' => 'datetime',
        'published_at' => 'datetime',
        'stale_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::updating(function (self $revision) {
            $immutableFields = [
                'project_id',
                'created_by_user_id',
                'number',
                'snapshot_json',
                'snapshot_hash',
            ];

            foreach ($immutableFields as $field) {
                if ($revision->isDirty($field)) {
                    return false;
                }
            }

            return true;
        });
    }

    // ==================== RELATIONSHIPS ====================

    /**
     * Revision belongs to a project
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * Revision was created by a user
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Publications for this revision
     */
    public function publications(): HasMany
    {
        return $this->hasMany(RevisionPublication::class, 'project_revision_id');
    }

    // ==================== SCOPES ====================

    /**
     * Scope: get revisions with locked status
     */
    public function scopeLocked($query)
    {
        return $query->where('status', 'locked');
    }

    /**
     * Scope: get revisions with published status
     */
    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    /**
     * Scope: get revisions with stale status
     */
    public function scopeStale($query)
    {
        return $query->where('status', 'stale');
    }

    /**
     * Scope: get revisions for a specific project
     */
    public function scopeForProject($query, $projectId)
    {
        return $query->where('project_id', $projectId);
    }

    /**
     * Scope: order by revision number descending (newest first)
     */
    public function scopeLatestFirst($query)
    {
        return $query->orderBy('number', 'desc');
    }

    // ==================== STATUS MUTATIONS ====================

    /**
     * Lock the revision (mark as immutable)
     */
    public function lock(): bool
    {
        if ($this->status === 'published' || $this->status === 'stale') {
            return false;
        }
        if ($this->status === 'locked') {
            return true;
        }
        return $this->update([
            'status' => 'locked',
            'locked_at' => Carbon::now(),
        ]);
    }

    /**
     * Publish the revision (make active)
     */
    public function publish(): bool
    {
        if ($this->status === 'stale') {
            return false;
        }
        if ($this->status === 'published') {
            return true;
        }
        if ($this->status !== 'locked') {
            return false;
        }
        return $this->update([
            'status' => 'published',
            'published_at' => Carbon::now(),
        ]);
    }

    /**
     * Mark revision as stale (superseded by another)
     */
    public function markStale(): bool
    {
        if ($this->status === 'stale') {
            return true;
        }
        return $this->update([
            'status' => 'stale',
            'stale_at' => Carbon::now(),
        ]);
    }

    // ==================== HELPERS ====================

    /**
     * Generate SHA256 hash of canonical snapshot JSON string
     */
    public static function generateSnapshotHash(string $canonicalJson): string
    {
        return hash('sha256', $canonicalJson);
    }

    /**
     * Check if snapshot hash matches content
     */
    public function verifySnapshot(): bool
    {
        if (!$this->snapshot_json || !$this->snapshot_hash) {
            return false;
        }

        $computed = self::generateSnapshotHash($this->snapshot_json);
        return hash_equals($this->snapshot_hash, $computed);
    }

    /**
     * Get the next revision number for a project
     */
    public static function nextNumberForProject(int $projectId): int
    {
        $latest = static::forProject($projectId)
            ->orderBy('number', 'desc')
            ->first();

        return ($latest?->number ?? 0) + 1;
    }

    /**
     * Get revision by project and number
     */
    public static function findByProjectAndNumber(int $projectId, int $number): ?self
    {
        return static::forProject($projectId)
            ->where('number', $number)
            ->first();
    }
}
