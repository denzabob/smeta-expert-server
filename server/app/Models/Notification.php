<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'body',
        'link_url',
        'link_label',
        'link_type',
        'audience_type',
        'audience_payload',
        'status',
        'send_at',
        'created_by',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'audience_payload' => 'array',
            'send_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    // ─── Relations ───

    public function deliveries(): HasMany
    {
        return $this->hasMany(UserNotification::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ─── Scopes ───

    public function scopeActive($query)
    {
        return $query->where('status', '!=', 'cancelled');
    }

    // ─── Helpers ───

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'scheduled']);
    }

    public function isCancellable(): bool
    {
        return in_array($this->status, ['draft', 'scheduled', 'sending', 'sent']);
    }

    /**
     * Get audience user IDs based on audience_type.
     *
     * @return \Illuminate\Support\Collection<int>
     */
    public function resolveAudienceUserIds(): \Illuminate\Support\Collection
    {
        return match ($this->audience_type) {
            'all' => User::pluck('id'),
            'users' => collect($this->audience_payload['user_ids'] ?? []),
            'segment' => collect(), // placeholder for future segment logic
            default => collect(),
        };
    }
}
