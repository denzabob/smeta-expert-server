<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AiLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'input_hash',
        'model_name',
        'provider_name',
        'fallback_used',
        'failover_chain',
        'prompt_tokens',
        'completion_tokens',
        'cost_usd',
        'latency_ms',
        'is_successful',
        'error_message',
        'error_type',
        'http_status',
        'metadata',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
        'cost_usd' => 'decimal:6',
        'latency_ms' => 'integer',
        'is_successful' => 'boolean',
        'fallback_used' => 'boolean',
        'failover_chain' => 'array',
        'http_status' => 'integer',
        'metadata' => 'array',
    ];

    /**
     * Связь с пользователем
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope для успешных запросов
     */
    public function scopeSuccessful($query)
    {
        return $query->where('is_successful', true);
    }

    /**
     * Scope для неуспешных запросов
     */
    public function scopeFailed($query)
    {
        return $query->where('is_successful', false);
    }

    /**
     * Scope для фильтрации по модели
     */
    public function scopeForModel($query, string $modelName)
    {
        return $query->where('model_name', $modelName);
    }

    /**
     * Scope для фильтрации по пользователю
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope для фильтрации по провайдеру
     */
    public function scopeForProvider($query, string $providerName)
    {
        return $query->where('provider_name', $providerName);
    }

    /**
     * Scope для фильтрации по периоду
     */
    public function scopeInPeriod($query, string $from, ?string $to = null)
    {
        $query->where('created_at', '>=', $from);
        if ($to) {
            $query->where('created_at', '<=', $to);
        }
        return $query;
    }

    /**
     * Получить общее количество токенов
     */
    public function getTotalTokensAttribute(): int
    {
        return ($this->prompt_tokens ?? 0) + ($this->completion_tokens ?? 0);
    }

    /**
     * Создать запись лога
     */
    public static function logRequest(
        string $inputHash,
        string $modelName,
        int $latencyMs,
        bool $isSuccessful,
        ?int $promptTokens = null,
        ?int $completionTokens = null,
        ?float $costUsd = null,
        ?string $errorMessage = null,
        ?array $metadata = null
    ): self {
        return self::create([
            'input_hash' => $inputHash,
            'model_name' => $modelName,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'cost_usd' => $costUsd,
            'latency_ms' => $latencyMs,
            'is_successful' => $isSuccessful,
            'error_message' => $errorMessage,
            'metadata' => $metadata,
        ]);
    }
}
