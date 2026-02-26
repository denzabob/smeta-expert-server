<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GlobalNormohourSource extends Model
{
    use HasFactory;

    protected $table = 'global_normohour_sources';

    protected $fillable = [
        'position_profile_id',
        'region_id',
        'source',
        'salary_value',
        'salary_value_min',
        'salary_value_max',
        'salary_period',
        'salary_month',
        'hours_per_month',
        'rate_per_hour',
        'source_date',
        'link',
        'note',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'salary_value' => 'decimal:2',
        'salary_value_min' => 'decimal:2',
        'salary_value_max' => 'decimal:2',
        'salary_month' => 'decimal:2',
        'hours_per_month' => 'decimal:2',
        'rate_per_hour' => 'decimal:2',
        'min_rate' => 'decimal:2',
        'max_rate' => 'decimal:2',
        'source_date' => 'date',
        'is_active' => 'boolean',
    ];

    /**
     * Отношение к профилю должности
     */
    public function positionProfile()
    {
        return $this->belongsTo(PositionProfile::class, 'position_profile_id');
    }

    /**
     * Отношение к регионам
     */
    public function region()
    {
        return $this->belongsTo(Region::class);
    }

    /**
     * Получить только активные источники
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Фильтр по профилю должности
     */
    public function scopeForProfile($query, $profileId)
    {
        return $query->where('position_profile_id', $profileId);
    }

    /**
     * Фильтр по регионам (с поддержкой NULL)
     */
    public function scopeForRegion($query, $regionId)
    {
        if ($regionId) {
            return $query->where(function ($q) use ($regionId) {
                $q->where('region_id', $regionId)
                  ->orWhereNull('region_id');
            });
        }
        return $query;
    }

    /**
     * Получить отсортированный список
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Проверить, является ли это диапазоном (range)
     */
    public function getIsRangeAttribute(): bool
    {
        return $this->salary_value_min !== null && $this->salary_value_max !== null;
    }

    /**
     * Получить тип ставки (single или range)
     */
    public function getTypeAttribute(): string
    {
        return $this->is_range ? 'range' : 'single';
    }
}
