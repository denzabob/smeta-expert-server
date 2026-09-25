<?php

declare(strict_types=1);

namespace App\Services\Expert;

final class ExpertStorageUsageSnapshot
{
    public function __construct(
        public readonly int $usedBytes,
        public readonly ?int $limitBytes,
        public readonly ?int $remainingBytes,
        public readonly ?float $usagePercent,
        public readonly int $materialsCount,
        public readonly int $reservedBytes = 0,
    ) {}

    /** @return array{used_bytes: int, reserved_bytes: int, limit_bytes: int|null, remaining_bytes: int|null, usage_percent: float|null, materials_count: int} */
    public function toArray(): array
    {
        return [
            'used_bytes' => $this->usedBytes,
            'reserved_bytes' => $this->reservedBytes,
            'limit_bytes' => $this->limitBytes,
            'remaining_bytes' => $this->remainingBytes,
            'usage_percent' => $this->usagePercent,
            'materials_count' => $this->materialsCount,
        ];
    }
}
