<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectProfileRate extends Model
{
    use HasFactory;

    protected $table = 'project_profile_rates';

    protected $fillable = [
        'project_id',
        'profile_id',
        'region_id',
        'rate_fixed',
        'fixed_at',
        'calculation_method',
        'sources_snapshot',
        'justification_snapshot',
        'is_locked',
        'locked_at',
        'locked_reason',
    ];

    protected $casts = [
        'rate_fixed' => 'decimal:2',
        'fixed_at' => 'datetime',
        'locked_at' => 'datetime',
        'sources_snapshot' => 'json',
        'is_locked' => 'boolean',
    ];

    /**
     * Отношение к проекту
     */
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Отношение к профилю должности
     */
    public function profile()
    {
        return $this->belongsTo(PositionProfile::class, 'profile_id');
    }

    /**
     * Отношение к регионам
     */
    public function region()
    {
        return $this->belongsTo(Region::class);
    }

    /**
     * Фильтр по проекту и профилю
     */
    public function scopeForProjectProfile($query, $projectId, $profileId)
    {
        return $query
            ->where('project_id', $projectId)
            ->where('profile_id', $profileId);
    }

    /**
     * Фильтр по разблокированным ставкам
     */
    public function scopeUnlocked($query)
    {
        return $query->where('is_locked', false);
    }

    /**
     * Фильтр по заблокированным ставкам
     */
    public function scopeLocked($query)
    {
        return $query->where('is_locked', true);
    }

    /**
     * Получить ставку с регионом (или без, если регион_id = null)
     */
    public function scopeForRegion($query, $projectId, $profileId, $regionId)
    {
        return $query
            ->where('project_id', $projectId)
            ->where('profile_id', $profileId)
            ->where('region_id', $regionId);
    }
}
