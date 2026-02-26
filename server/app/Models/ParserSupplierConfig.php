<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParserSupplierConfig extends Model
{
    protected $fillable = [
        'supplier_name',
        'config',
    ];

    protected $casts = [
        'config' => 'array',
    ];
}
