<?php

namespace App\Services\Billing;

class FeatureGateResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly string $featureCode,
        public readonly string $reason,
        public readonly string $source,
        public readonly bool $logOnly,
    ) {}
}
