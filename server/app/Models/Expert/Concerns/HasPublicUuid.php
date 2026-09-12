<?php

namespace App\Models\Expert\Concerns;

use Illuminate\Support\Str;

trait HasPublicUuid
{
    protected static function bootHasPublicUuid(): void
    {
        static::creating(function ($model): void {
            $model->public_id ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
