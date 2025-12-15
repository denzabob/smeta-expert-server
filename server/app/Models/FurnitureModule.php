<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FurnitureModule extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'article',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function details()
    {
        return $this->hasMany(Detail::class, 'module_id');
    }
}
