<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TrustedDevice extends Model
{
    protected $fillable = [
        'user_id',
        'device_id',
        'device_secret_hash',
        'user_agent',
        'ip_first',
        'ip_last',
        'last_used_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Пользователь, которому принадлежит устройство.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Проверить, отозвано ли устройство.
     */
    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Отозвать устройство.
     */
    public function revoke(): void
    {
        $this->update(['revoked_at' => now()]);
    }

    /**
     * Проверить device_secret.
     */
    public function verifySecret(string $secret): bool
    {
        return Hash::check($secret, $this->device_secret_hash);
    }

    /**
     * Создать новое доверенное устройство.
     *
     * @return array{device: TrustedDevice, device_id: string, device_secret: string}
     */
    public static function createForUser(User $user, string $userAgent, string $ip): array
    {
        $deviceId = (string) Str::uuid();
        $deviceSecret = Str::random(64);

        $device = static::create([
            'user_id' => $user->id,
            'device_id' => $deviceId,
            'device_secret_hash' => Hash::make($deviceSecret),
            'user_agent' => Str::limit($userAgent, 512),
            'ip_first' => $ip,
            'ip_last' => $ip,
            'last_used_at' => now(),
        ]);

        return [
            'device' => $device,
            'device_id' => $deviceId,
            'device_secret' => $deviceSecret,
        ];
    }

    /**
     * Найти активное доверенное устройство по device_id.
     */
    public static function findActiveByDeviceId(string $deviceId): ?self
    {
        return static::where('device_id', $deviceId)
            ->whereNull('revoked_at')
            ->first();
    }

    /**
     * Краткое описание устройства (из User-Agent).
     */
    public function getDeviceLabelAttribute(): string
    {
        $ua = $this->user_agent ?? 'Unknown';

        // Простой парсинг
        if (str_contains($ua, 'Chrome')) {
            $browser = 'Chrome';
        } elseif (str_contains($ua, 'Firefox')) {
            $browser = 'Firefox';
        } elseif (str_contains($ua, 'Safari')) {
            $browser = 'Safari';
        } elseif (str_contains($ua, 'Edge')) {
            $browser = 'Edge';
        } else {
            $browser = 'Browser';
        }

        if (str_contains($ua, 'Windows')) {
            $os = 'Windows';
        } elseif (str_contains($ua, 'Mac')) {
            $os = 'macOS';
        } elseif (str_contains($ua, 'Linux')) {
            $os = 'Linux';
        } elseif (str_contains($ua, 'Android')) {
            $os = 'Android';
        } elseif (str_contains($ua, 'iPhone') || str_contains($ua, 'iPad')) {
            $os = 'iOS';
        } else {
            $os = 'Unknown OS';
        }

        return "{$browser} / {$os}";
    }
}
