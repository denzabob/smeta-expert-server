<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'pin_enabled',
        'pin_hash',
        'pin_changed_at',
        'pin_attempts',
        'pin_locked_until',
        'current_session_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'pin_hash',
        'current_session_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'pin_enabled' => 'boolean',
            'pin_changed_at' => 'datetime',
            'pin_locked_until' => 'datetime',
        ];
    }

    /**
     * Получить настройки пользователя
     */
    public function settings()
    {
        return $this->hasOne(UserSettings::class);
    }

    /**
     * Доверенные устройства пользователя.
     */
    public function trustedDevices()
    {
        return $this->hasMany(TrustedDevice::class);
    }

    /**
     * Активные (не отозванные) доверенные устройства.
     */
    public function activeTrustedDevices()
    {
        return $this->trustedDevices()->whereNull('revoked_at');
    }

    /**
     * Установить PIN-код (хэширует).
     */
    public function setPin(string $pin): void
    {
        $this->pin_hash = Hash::make($pin);
        $this->pin_enabled = true;
        $this->pin_changed_at = now();
        $this->pin_attempts = 0;
        $this->pin_locked_until = null;
        $this->save();
    }

    /**
     * Проверить PIN-код.
     */
    public function verifyPin(string $pin): bool
    {
        if (!$this->pin_enabled || !$this->pin_hash) {
            return false;
        }

        return Hash::check($pin, $this->pin_hash);
    }

    /**
     * Проверить, заблокирован ли ввод PIN.
     */
    public function isPinLocked(): bool
    {
        return $this->pin_locked_until && $this->pin_locked_until->isFuture();
    }

    /**
     * Зарегистрировать неудачную попытку ввода PIN.
     */
    public function recordFailedPinAttempt(): void
    {
        $this->increment('pin_attempts');
        $this->refresh();

        // После 5 неудач — блокировка на 15 минут
        if ($this->pin_attempts >= 5) {
            $this->pin_locked_until = now()->addMinutes(15);
            $this->save();
        }
    }

    /**
     * Сбросить счётчик попыток PIN.
     */
    public function resetPinAttempts(): void
    {
        $this->update([
            'pin_attempts' => 0,
            'pin_locked_until' => null,
        ]);
    }

    /**
     * Отключить PIN.
     */
    public function disablePin(): void
    {
        $this->update([
            'pin_enabled' => false,
            'pin_hash' => null,
            'pin_changed_at' => null,
            'pin_attempts' => 0,
            'pin_locked_until' => null,
        ]);
    }

    // связи для пользовательских материалов можно добавить позже при необходимости
}
