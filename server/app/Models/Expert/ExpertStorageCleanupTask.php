<?php

namespace App\Models\Expert;

use Illuminate\Database\Eloquent\Model;

class ExpertStorageCleanupTask extends Model
{
    protected $fillable = [
        'disk',
        'path',
        'kind',
        'attempts',
        'last_error',
        'next_attempt_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
        ];
    }
}
