<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;

/**
 * Step-up authentication challenge.
 *
 * @property string $id
 * @property int $user_id
 * @property string $scope
 * @property array $allowed_methods
 * @property string $status
 * @property string|null $completed_method
 * @property string|null $token
 * @property \Illuminate\Support\Carbon|null $token_expires_at
 * @property string|null $phone_challenge_id
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property string|null $ip_address
 */
class StepUpChallenge extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'scope',
        'allowed_methods',
        'status',
        'completed_method',
        'token',
        'token_expires_at',
        'phone_challenge_id',
        'expires_at',
        'completed_at',
        'ip_address',
    ];

    protected $hidden = [
        'token', // Never expose raw step-up tokens in serialised responses
    ];

    protected function casts(): array
    {
        return [
            'allowed_methods' => 'array',
            'expires_at' => 'datetime',
            'token_expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    // ─── Scopes ─────────────────────────────────────────────────────────────

    /**
     * Valid scopes supported by the step-up system.
     */
    public const SCOPES = [
        'set_quick_pin',
        'change_email',
        'change_phone',
        'set_password',
        'unlink_auth_method',
        'view_security_sessions',
        'revoke_all_devices',
    ];

    // ─── Relations ──────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ─── State helpers ──────────────────────────────────────────────────────

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isExpiredVerification(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isTokenValid(): bool
    {
        return $this->isCompleted()
            && $this->token !== null
            && $this->token_expires_at !== null
            && $this->token_expires_at->isFuture();
    }

    /**
     * Mark this challenge as expired (e.g. when TTL elapsed during pending state).
     */
    public function markExpired(): void
    {
        $this->update(['status' => 'expired']);
    }

    /**
     * Consume the step-up token so it cannot be reused.
     */
    public function consume(): void
    {
        // Move token_expires_at to the past so isTokenValid() returns false.
        $this->update(['token_expires_at' => now()->subSecond()]);
    }

    // ─── Static lookup ──────────────────────────────────────────────────────

    /**
     * Find a valid (completed, unexpired) challenge by token, user and scope.
     * Returns null if not found or token is no longer valid.
     */
    public static function findValidToken(string $token, int $userId, string $scope): ?self
    {
        return self::where('token', hash('sha256', $token)) // always compare against stored hash
            ->where('user_id', $userId)
            ->where('scope', $scope)
            ->where('status', 'completed')
            ->where('token_expires_at', '>', now())
            ->first();
    }
}
