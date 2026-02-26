<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectManualOperation extends Model
{
    use HasFactory;

    protected $table = 'project_manual_operations';

    protected $fillable = [
        'project_id',
        'operation_id',
        'quantity',
        'note',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function operation()
    {
        return $this->belongsTo(Operation::class);
    }
}
