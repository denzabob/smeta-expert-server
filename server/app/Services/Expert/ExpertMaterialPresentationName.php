<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Models\Expert\ExpertProjectMaterial;

final class ExpertMaterialPresentationName
{
    public static function resolve(ExpertProjectMaterial $material): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $material->original_name);
        $name = is_string($name) ? trim($name) : '';

        if ($name !== '') {
            return $name;
        }

        $extension = strtolower(ltrim(trim((string) $material->extension), '.'));

        return 'Материал без названия'.($extension === '' ? '' : '.'.$extension);
    }
}
