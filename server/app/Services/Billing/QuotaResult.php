<?php

namespace App\Services\Billing;

class QuotaResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly string $metricCode,
        public readonly float $quantity,
        public readonly string $reason,
        public readonly bool $enforced,
        public readonly ?float $limit = null,
        public readonly ?float $remaining = null,
    ) {}
}
