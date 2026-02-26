<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectNormohourSource extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'source',
        'position_profile',
        'salary_range',
        'period',
        'link',
        'note',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}
