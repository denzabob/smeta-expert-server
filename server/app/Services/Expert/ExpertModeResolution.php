<?php

declare(strict_types=1);

namespace App\Services\Expert;

final readonly class ExpertModeResolution
{
    public const FAST = 'fast';
    public const AUTO = 'auto';
    public const DEEP = 'deep';

    public function __construct(
        public string $requestedMode,
        public string $resolvedMode,
        public string $routeReason,
    ) {}

    public static function normalise(?string $mode): string
    {
        return in_array($mode, [self::FAST, self::AUTO, self::DEEP], true) ? $mode : self::AUTO;
    }

    public function toMetadata(): array
    {
        return [
            'requested_mode' => $this->requestedMode,
            'resolved_mode' => $this->resolvedMode,
            'route_reason' => $this->routeReason,
        ];
    }
}
