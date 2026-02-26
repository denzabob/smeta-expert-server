<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class WorkPreset extends Model
{
    use HasFactory;

    protected $fillable = [
        'normalized_title',
        'context_hash',
        'context_json',
        'steps_json',
        'total_hours',
        'fingerprint',
        'usage_count',
        'status',
        'source',
    ];

    protected $casts = [
        'context_json' => 'array',
        'steps_json' => 'array',
        'total_hours' => 'decimal:2',
        'usage_count' => 'integer',
    ];

    /**
     * Статусы пресета
     */
    const STATUS_DRAFT = 'draft';
    const STATUS_CANDIDATE = 'candidate';
    const STATUS_VERIFIED = 'verified';
    const STATUS_DEPRECATED = 'deprecated';

    /**
     * Источники создания
     */
    const SOURCE_MANUAL = 'manual';
    const SOURCE_AI = 'ai';
    const SOURCE_IMPORTED = 'imported';

    /**
     * Порог для автоматического повышения до candidate
     */
    const USAGE_THRESHOLD_FOR_CANDIDATE = 10;

    /**
     * Scope для активных пресетов (candidate или verified)
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', [self::STATUS_CANDIDATE, self::STATUS_VERIFIED]);
    }

    /**
     * Scope для поиска по context_hash и normalized_title
     */
    public function scopeByContext($query, string $contextHash, string $normalizedTitle)
    {
        return $query
            ->where('context_hash', $contextHash)
            ->where('normalized_title', $normalizedTitle);
    }

    /**
     * Scope с приоритетом verified > candidate, затем по usage_count
     */
    public function scopeOrderByPriority($query)
    {
        return $query
            ->orderByRaw("CASE WHEN status = 'verified' THEN 0 WHEN status = 'candidate' THEN 1 ELSE 2 END")
            ->orderByDesc('usage_count');
    }

    /**
     * Увеличить счетчик использований и проверить на повышение статуса
     */
    public function incrementUsage(): void
    {
        $this->increment('usage_count');

        // Автоматическое повышение draft -> candidate при достижении порога
        if ($this->status === self::STATUS_DRAFT && $this->usage_count >= self::USAGE_THRESHOLD_FOR_CANDIDATE) {
            $this->update(['status' => self::STATUS_CANDIDATE]);
        }
    }

    /**
     * Получить общее количество часов из steps_json
     */
    public function calculateTotalHours(): float
    {
        $steps = $this->steps_json ?? [];
        return array_reduce($steps, fn($sum, $step) => $sum + (float)($step['hours'] ?? 0), 0.0);
    }
}
