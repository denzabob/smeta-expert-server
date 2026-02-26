<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Region extends Model
{
    use HasFactory;

    protected $table = 'regions';

    protected $fillable = [
        'name',
        'code',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    /**
     * Отношение к источникам нормо-часов
     */
    public function normohourSources()
    {
        return $this->hasMany(GlobalNormohourSource::class);
    }

    /**
     * Отношение к ставкам проектов
     */
    public function projectProfileRates()
    {
        return $this->hasMany(ProjectProfileRate::class);
    }

    /**
     * Отношение к проектам
     */
    public function projects()
    {
        return $this->hasMany(Project::class);
    }

    /**
     * Фильтр по названию
     */
    public function scopeByName($query, $name)
    {
        return $query->where('name', $name);
    }

    /**
     * Получить отсортированные регионы
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
