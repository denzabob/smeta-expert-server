<?php

namespace App\Support;

use App\Models\Project;
use Illuminate\Support\Str;

class ProjectPublicId
{
    public const PREFIX = 'prj_';

    public static function generate(): string
    {
        do {
            $publicId = self::PREFIX . strtolower((string) Str::ulid());
        } while (Project::query()->where('public_id', $publicId)->exists());

        return $publicId;
    }
}
