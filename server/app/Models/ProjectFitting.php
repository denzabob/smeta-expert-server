<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectFitting extends Model
{
    use HasFactory;

    protected $table = 'project_fittings';

    protected $fillable = [
        'project_id',
        'name',
        'article',
        'unit',
        'quantity',
        'unit_price',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}
