<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthVerificationChallenge extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'purpose',
        'phone',
        'email',
        'code_hash',
        'expires_at',
        'attempts_left',
        'resend_available_at',
        'status',
        'verified_at',
        'current_channel',
        'channel_attempt_order',
        'provider_message_id',
        'call_phone',
        'call_phone_pretty',
        'last_error',
        'provider_payload',
        'user_id',
        'ip_address',
    ];

    protected $hidden = [
        'code_hash',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'resend_available_at' => 'datetime',
            'verified_at' => 'datetime',
            'channel_attempt_order' => 'array',
            'provider_payload' => 'array',
        ];
    }

    /* ──── Relationships ──── */

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /* ──── Query Scopes ──── */

    public function scopePending($query)
    {
        return $query->where('status', 'pending')
            ->where('expires_at', '>', now());
    }

    /* ──── Domain Methods ──── */

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isPending(): bool
    {
        return $this->status === 'pending' && !$this->isExpired();
    }

    public function hasAttemptsLeft(): bool
    {
        return $this->attempts_left > 0;
    }

    public function canResend(): bool
    {
        if (!$this->resend_available_at) {
            return true;
        }
        return $this->resend_available_at->isPast();
    }

    public function verifyCode(string $code): bool
    {
        return Hash::check($code, $this->code_hash);
    }

    public function recordFailedAttempt(): void
    {
        $this->decrement('attempts_left');

        if ($this->attempts_left <= 0) {
            $this->update(['status' => 'failed']);
        }
    }

    public function markVerified(): void
    {
        $this->update([
            'status' => 'verified',
            'verified_at' => now(),
        ]);
    }

    public function markExpired(): void
    {
        $this->update(['status' => 'expired']);
    }

    public function markCanceled(): void
    {
        $this->update(['status' => 'canceled']);
    }

    /**
     * Rate-limit check: count recent challenges for phone+IP combo.
     */
    public static function recentCount(string $phone, string $ip, int $minutes = 60): int
    {
        return static::where('phone', $phone)
            ->where('ip_address', $ip)
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->count();
    }

    /**
     * Rate-limit check: count recent challenges for IP only.
     */
    public static function recentCountByIp(string $ip, int $minutes = 60): int
    {
        return static::where('ip_address', $ip)
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->count();
    }
}
