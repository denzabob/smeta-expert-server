<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class Unit extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'description',
        'user_id',
        'origin',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($unit) {
            if (!isset($unit->user_id)) {
                $unit->user_id = Auth::id();
            }
            if (!isset($unit->origin)) {
                $unit->origin = $unit->user_id ? 'user' : 'system';
            }
        });
    }
}
