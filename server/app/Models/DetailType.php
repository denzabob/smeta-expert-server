<?php
// app/Models/DetailType.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DetailType extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'edge_processing',
        'components',
        'user_id',
        'origin',
    ];

    protected $casts = [
        'components' => 'array', // автоматически сериализует/десериализует JSON
    ];

    public function detailTypeOperations()
    {
        return $this->hasMany(DetailTypeOperation::class);
    }

    public function operations()
    {
        return $this->belongsToMany(Operation::class, 'detail_type_operations', 'detail_type_id', 'operation_id')
            ->withPivot('quantity_formula');
    }

    public function positions()
    {
        return $this->hasMany(ProjectPosition::class, 'detail_type_id');
    }
}
