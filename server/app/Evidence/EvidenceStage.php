<?php

namespace App\Evidence;

final class EvidenceStage
{
    public const INIT = 'INIT';
    public const FETCH = 'FETCH';
    public const PAGE_CLASSIFY = 'PAGE_CLASSIFY';
    public const EXTRACT = 'EXTRACT';
    public const CAPTURE = 'CAPTURE';
    public const VALIDATE = 'VALIDATE';
    public const PERSIST_ARTIFACT = 'PERSIST_ARTIFACT';
    public const LINK_HISTORY = 'LINK_HISTORY';
    public const LINK_REVISION = 'LINK_REVISION';
    public const DONE = 'DONE';

    public static function all(): array
    {
        return [
            self::INIT,
            self::FETCH,
            self::PAGE_CLASSIFY,
            self::EXTRACT,
            self::CAPTURE,
            self::VALIDATE,
            self::PERSIST_ARTIFACT,
            self::LINK_HISTORY,
            self::LINK_REVISION,
            self::DONE,
        ];
    }
}

