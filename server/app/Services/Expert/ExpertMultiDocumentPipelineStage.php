<?php

declare(strict_types=1);

namespace App\Services\Expert;

final class ExpertMultiDocumentPipelineStage
{
    public const PREPARE = 'prepare';
    public const MAP = 'map';
    public const COMPARE = 'compare';
    public const REDUCE = 'reduce';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::PREPARE, self::MAP, self::COMPARE, self::REDUCE];
    }
}
