<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectLaborWork extends Model
{
    protected $fillable = [
        'project_id',
        'position_profile_id',
        'title',
        'basis',
        'hours',
        'hours_source',
        'hours_manual',
        'note',
        'sort_order',
        'project_profile_rate_id',
        'rate_per_hour',
        'cost_total',
        'rate_snapshot',
    ];

    protected $casts = [
        'hours' => 'float',
        'hours_manual' => 'decimal:2',
        'rate_per_hour' => 'decimal:2',
        'cost_total' => 'decimal:2',
        'rate_snapshot' => 'array',
        'sort_order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Отношение к проекту
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Отношение к профилю должности
     */
    public function positionProfile(): BelongsTo
    {
        return $this->belongsTo(PositionProfile::class);
    }

    /**
     * Отношение к ставке профиля проекта
     */
    public function profileRate(): BelongsTo
    {
        return $this->belongsTo(ProjectProfileRate::class, 'project_profile_rate_id');
    }

    /**
     * Отношение к подоперациям (шагам)
     */
    public function steps(): HasMany
    {
        return $this->hasMany(ProjectLaborWorkStep::class, 'project_labor_work_id')
            ->orderBy('sort_order');
    }

    /**
     * Вычисленная стоимость работ (используется если cost_total не установлена)
     * 
     * Приоритет:
     * 1. Если установлена cost_total (из привязанной ставки) - использовать её
     * 2. Если установлена rate_per_hour (из привязанной ставки) - использовать её для расчёта
     * 3. Если есть регион-зависимая ставка - использовать project.normohour_rate
     * 4. Иначе NULL
     * 
     * Использование: $work->cost
     */
    public function getCostAttribute(): float|null
    {
        // Приоритет 1: Если установлена cost_total - использовать её
        if ($this->cost_total !== null) {
            return (float)$this->cost_total;
        }

        // Приоритет 2: Если установлена rate_per_hour - рассчитать стоимость
        if ($this->rate_per_hour !== null) {
            return round($this->hours * (float)$this->rate_per_hour, 2);
        }

        // Приоритет 3: Fallback на ставку проекта (старый способ)
        if ($this->project && $this->project->normohour_rate) {
            return round($this->hours * $this->project->normohour_rate, 2);
        }

        // Приоритет 4: Ставка не найдена
        return null;
    }

    /**
     * Проверить, есть ли ошибка при привязке ставки
     */
    public function hasRateError(): bool
    {
        if (!$this->rate_snapshot || !is_array($this->rate_snapshot)) {
            return false;
        }

        return isset($this->rate_snapshot['error']);
    }

    /**
     * Получить сообщение об ошибке привязки ставки
     */
    public function getRateErrorMessage(): string|null
    {
        if (!$this->hasRateError()) {
            return null;
        }

        return $this->rate_snapshot['error'] ?? 'Unknown error';
    }

    /**
     * Получить фактическое количество часов
     * 
     * Приоритет:
     * 1. Если hours_source = 'from_steps' - считать сумму часов подопераций
     * 2. Если hours_source = 'manual' - использовать hours_manual (или hours)
     */
    public function getEffectiveHours(): float
    {
        if ($this->hours_source === 'from_steps') {
            return (float)($this->steps()->sum('hours') ?? 0);
        }

        // hours_source = 'manual'
        return (float)($this->hours_manual ?? $this->hours ?? 0);
    }

    /**
     * Установить часы вручную
     */
    public function setManualHours(float $hours): void
    {
        $this->hours_source = 'manual';
        $this->hours_manual = $hours;
        $this->hours = $hours; // Синхронизируем основное поле для совместимости
        $this->save();
    }

    /**
     * Переключить источник часов на подоперации
     * Пересчитает часы на основе всех подопераций
     */
    public function setHoursFromSteps(): void
    {
        $this->hours_source = 'from_steps';
        $totalHours = $this->steps()->sum('hours') ?? 0;
        $this->hours = $totalHours; // Синхронизируем основное поле
        $this->save();
    }

    /**
     * Проверить, задаются ли часы вручную
     */
    public function isManualHours(): bool
    {
        return $this->hours_source === 'manual';
    }

    /**
     * Проверить, считаются ли часы по подоперациям
     */
    public function isFromSteps(): bool
    {
        return $this->hours_source === 'from_steps';
    }
}
