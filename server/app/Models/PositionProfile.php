<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PositionProfile extends Model
{
    use HasFactory;

    protected $table = 'position_profiles';

    protected $fillable = [
        'name',
        'description',
        'sort_order',
        'rate_model',
        'employer_contrib_pct',
        'base_hours_month',
        'billable_hours_month',
        'profit_pct',
        'rounding_mode',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'employer_contrib_pct' => 'float',
        'base_hours_month' => 'integer',
        'billable_hours_month' => 'integer',
        'profit_pct' => 'float',
    ];

    /**
     * Является ли профиль подрядной моделью
     */
    public function isContractor(): bool
    {
        return ($this->rate_model ?? 'labor') === 'contractor';
    }

    /**
     * Получить параметры модели ставки для передачи в RateModelCalculator
     */
    public function getRateModelParams(): array
    {
        return [
            'rate_model' => $this->rate_model ?? 'labor',
            'employer_contrib_pct' => $this->employer_contrib_pct ?? 30.0,
            'base_hours_month' => $this->base_hours_month ?? 160,
            'billable_hours_month' => $this->billable_hours_month ?? 120,
            'profit_pct' => $this->profit_pct ?? 15.0,
            'rounding_mode' => $this->rounding_mode ?? 'none',
        ];
    }

    /**
     * Отношение к источникам нормо-часов
     */
    public function normohourSources()
    {
        return $this->hasMany(GlobalNormohourSource::class, 'position_profile_id');
    }

    /**
     * Отношение к ставкам проектов
     */
    public function projectProfileRates()
    {
        return $this->hasMany(ProjectProfileRate::class, 'profile_id');
    }

    /**
     * Отношение к работам проектов
     */
    public function projectLaborWorks()
    {
        return $this->hasMany(ProjectLaborWork::class, 'position_profile_id');
    }

    /**
     * Фильтр по названию
     */
    public function scopeByName($query, $name)
    {
        return $query->where('name', $name);
    }

    /**
     * Получить отсортированные профили
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
