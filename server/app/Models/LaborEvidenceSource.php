<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LaborEvidenceSource extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'region_id',
        'labor_profile_id',
        'provider_id',
        'evidence_record_id',
        'source_title',
        'source_url',
        'source_date',
        'employer_name',
        'vacancy_title',
        'vacancy_description',
        'vacancy_excerpt',
        'salary_raw_text',
        'salary_value',
        'salary_value_min',
        'salary_value_max',
        'salary_period',
        'hours_per_month',
        'derived_hourly_rate',
        'currency',
        'note',
        'captured_via',
        'verification_status',
        'is_active',
    ];

    protected $casts = [
        'source_date' => 'date',
        'salary_value' => 'decimal:2',
        'salary_value_min' => 'decimal:2',
        'salary_value_max' => 'decimal:2',
        'hours_per_month' => 'integer',
        'derived_hourly_rate' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(LaborProvider::class, 'provider_id');
    }

    public function laborProfile(): BelongsTo
    {
        return $this->belongsTo(LaborProfile::class, 'labor_profile_id');
    }

    public function evidenceRecord(): BelongsTo
    {
        return $this->belongsTo(EvidenceRecord::class, 'evidence_record_id');
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_labor_evidence_sources')
            ->withTimestamps();
    }

    public function scopeOwnedBy($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
