<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectLaborWorkStep extends Model
{
    use HasFactory;

    protected $table = 'project_labor_work_steps';

    protected $fillable = [
        'project_labor_work_id',
        'title',
        'basis',
        'input_data',
        'hours',
        'note',
        'sort_order',
    ];

    protected $casts = [
        'hours' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public function laborWork()
    {
        return $this->belongsTo(ProjectLaborWork::class, 'project_labor_work_id');
    }
}
