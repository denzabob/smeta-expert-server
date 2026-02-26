<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

/**
 * Модель для хранения собранных URL товаров от поставщиков.
 * Служит очередью для парсинга товаров.
 * 
 * @property int $id
 * @property string $supplier_name
 * @property string|null $material_type
 * @property string $url
 * @property bool $is_valid
 * @property Carbon|null $collected_at
 * @property Carbon|null $validated_at
 * @property int $retries
 * @property string|null $validation_error
 * @property string $status
 * @property int $attempts
 * @property string|null $locked_by
 * @property Carbon|null $locked_at
 * @property Carbon|null $last_attempt_at
 * @property Carbon|null $last_parsed_at
 * @property Carbon|null $next_retry_at
 * @property string|null $last_error_code
 * @property string|null $last_error_message
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class SupplierUrl extends Model
{
    use HasFactory;

    // Константы статусов
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';
    public const STATUS_BLOCKED = 'blocked';

    // Константы кодов ошибок
    public const ERROR_NAV_TIMEOUT = 'NAV_TIMEOUT';
    public const ERROR_SELECTOR_NOT_FOUND = 'SELECTOR_NOT_FOUND';
    public const ERROR_PRICE_PARSE_FAILED = 'PRICE_PARSE_FAILED';
    public const ERROR_HTTP_403 = 'HTTP_403';
    public const ERROR_HTTP_404 = 'HTTP_404';
    public const ERROR_NETWORK = 'NETWORK_ERROR';
    public const ERROR_WORKER_TIMEOUT = 'WORKER_TIMEOUT';
    public const ERROR_UNKNOWN = 'UNKNOWN';

    // Лимиты
    public const MAX_ATTEMPTS = 5;
    public const PROCESSING_TTL_MINUTES = 30;
    public const REPARSE_INTERVAL_DAYS = 7;

    // Backoff интервалы (в минутах)
    public const BACKOFF_INTERVALS = [
        1 => 5,       // 5 минут
        2 => 30,      // 30 минут
        3 => 120,     // 2 часа
        4 => 720,     // 12 часов
        5 => 2880,    // 48 часов
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'supplier_name',
        'material_type',
        'url',
        'is_valid',
        'collected_at',
        'validated_at',
        'last_seen_at',
        'retries',
        'validation_error',
        'status',
        'attempts',
        'locked_by',
        'locked_at',
        'last_attempt_at',
        'last_parsed_at',
        'next_retry_at',
        'last_error_code',
        'last_error_message',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_valid' => 'boolean',
        'collected_at' => 'datetime',
        'validated_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'locked_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'last_parsed_at' => 'datetime',
        'next_retry_at' => 'datetime',
        'retries' => 'integer',
        'attempts' => 'integer',
    ];

    /**
     * Scope: только валидные URL.
     */
    public function scopeValid(Builder $query): Builder
    {
        return $query->where('is_valid', true);
    }

    /**
     * Scope: только невалидные URL.
     */
    public function scopeInvalid(Builder $query): Builder
    {
        return $query->where('is_valid', false);
    }

    /**
     * Scope: фильтр по поставщику.
     */
    public function scopeForSupplier(Builder $query, string $supplier): Builder
    {
        return $query->where('supplier_name', $supplier);
    }

    /**
     * Scope: фильтр по типу материала.
     */
    public function scopeForMaterialType(Builder $query, string $type): Builder
    {
        return $query->where('material_type', $type);
    }

    /**
     * Scope: собранные за последние N дней.
     */
    public function scopeRecentlyCollected(Builder $query, int $days = 7): Builder
    {
        return $query->where('collected_at', '>=', Carbon::now()->subDays($days));
    }

    /**
     * Scope: URL готовые к парсингу (pending).
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope: URL в обработке.
     */
    public function scopeProcessing(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PROCESSING);
    }

    /**
     * Scope: успешно обработанные.
     */
    public function scopeDone(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DONE);
    }

    /**
     * Scope: с ошибкой (требуют повтора).
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Scope: заблокированные.
     */
    public function scopeBlocked(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_BLOCKED);
    }

    /**
     * Scope: URL готовые для claim.
     * 
     * После full-scan-reset все URL в pending, поэтому claimable = pending + unlocked.
     * 
     * Критерии:
     * 1. status = pending
     * 2. is_valid = true
     * 3. locked_by IS NULL (или lock истёк > 30 мин)
     */
    public function scopeClaimable(Builder $query, int $reparseDays = null): Builder
    {
        $staleCutoff = Carbon::now()->subMinutes(self::PROCESSING_TTL_MINUTES);
        
        return $query->where('is_valid', true)
            ->where('status', self::STATUS_PENDING)
            ->where(function ($q) use ($staleCutoff) {
                // Не заблокирован
                $q->whereNull('locked_by')
                  // Или lock истёк (stale)
                  ->orWhere(function ($q2) use ($staleCutoff) {
                      $q2->whereNotNull('locked_at')
                         ->where('locked_at', '<', $staleCutoff);
                  });
            });
    }

    /**
     * Scope: зависшие processing (TTL истёк).
     */
    public function scopeStaleProcessing(Builder $query, int $ttlMinutes = null): Builder
    {
        $ttlMinutes = $ttlMinutes ?? self::PROCESSING_TTL_MINUTES;
        
        return $query->where('status', self::STATUS_PROCESSING)
            ->where('locked_at', '<', Carbon::now()->subMinutes($ttlMinutes));
    }

    /**
     * Помечает URL как невалидный.
     */
    public function markAsInvalid(?string $error = null): bool
    {
        return $this->update([
            'is_valid' => false,
            'validation_error' => $error,
            'validated_at' => Carbon::now(),
        ]);
    }

    /**
     * Помечает URL как валидный.
     */
    public function markAsValid(): bool
    {
        return $this->update([
            'is_valid' => true,
            'validation_error' => null,
            'validated_at' => Carbon::now(),
            'retries' => 0,
        ]);
    }

    /**
     * Заблокировать URL для обработки воркером.
     */
    public function lockForProcessing(string $workerId): bool
    {
        return $this->update([
            'status' => self::STATUS_PROCESSING,
            'locked_by' => $workerId,
            'locked_at' => Carbon::now(),
            'last_attempt_at' => Carbon::now(),
        ]);
    }

    /**
     * Пометить как успешно обработанный.
     */
    public function markAsDone(?Carbon $parsedAt = null): bool
    {
        return $this->update([
            'status' => self::STATUS_DONE,
            'last_parsed_at' => $parsedAt ?? Carbon::now(),
            'locked_by' => null,
            'locked_at' => null,
            'next_retry_at' => null,
            'last_error_code' => null,
            'last_error_message' => null,
        ]);
    }

    /**
     * Пометить как failed с backoff.
     */
    public function markAsFailed(string $errorCode, ?string $errorMessage = null): bool
    {
        $newAttempts = $this->attempts + 1;
        
        // Если превышен лимит - блокируем
        if ($newAttempts >= self::MAX_ATTEMPTS) {
            return $this->markAsBlocked($errorCode, $errorMessage);
        }
        
        // Вычисляем backoff
        $backoffMinutes = self::BACKOFF_INTERVALS[$newAttempts] ?? 2880;
        
        return $this->update([
            'status' => self::STATUS_FAILED,
            'attempts' => $newAttempts,
            'next_retry_at' => Carbon::now()->addMinutes($backoffMinutes),
            'locked_by' => null,
            'locked_at' => null,
            'last_error_code' => $errorCode,
            'last_error_message' => $errorMessage,
        ]);
    }

    /**
     * Пометить как заблокированный.
     */
    public function markAsBlocked(string $errorCode, ?string $errorMessage = null): bool
    {
        return $this->update([
            'status' => self::STATUS_BLOCKED,
            'locked_by' => null,
            'locked_at' => null,
            'last_error_code' => $errorCode,
            'last_error_message' => $errorMessage,
        ]);
    }

    /**
     * Сбросить зависший processing в pending/failed.
     */
    public function resetStaleProcessing(): bool
    {
        return $this->update([
            'status' => self::STATUS_PENDING,
            'locked_by' => null,
            'locked_at' => null,
        ]);
    }

    /**
     * Увеличивает счётчик попыток валидации.
     */
    public function incrementRetries(): bool
    {
        return $this->increment('retries');
    }

    /**
     * Проверяет, превышен ли лимит попыток валидации.
     */
    public function retriesExceeded(int $maxRetries = 3): bool
    {
        return $this->retries >= $maxRetries;
    }

    /**
     * Проверяет, превышен ли лимит попыток парсинга.
     */
    public function attemptsExceeded(): bool
    {
        return $this->attempts >= self::MAX_ATTEMPTS;
    }

    /**
     * Получить читаемое описание статуса.
     */
    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'Ожидает',
            self::STATUS_PROCESSING => 'В обработке',
            self::STATUS_DONE => 'Готово',
            self::STATUS_FAILED => 'Ошибка',
            self::STATUS_BLOCKED => 'Заблокирован',
            default => 'Неизвестно',
        };
    }
}
