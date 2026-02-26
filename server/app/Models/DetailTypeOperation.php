<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DetailTypeOperation extends Model
{
    use HasFactory;

    protected $table = 'detail_type_operations';

    protected $fillable = [
        'detail_type_id',
        'operation_id',
        'quantity_formula',
    ];

    public function detailType()
    {
        return $this->belongsTo(DetailType::class);
    }

    public function operation()
    {
        return $this->belongsTo(Operation::class);
    }
}
