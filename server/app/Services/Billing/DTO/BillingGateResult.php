<?php

namespace App\Services\Billing\DTO;

class BillingGateResult
{
    public function __construct(
        public bool $allowed,
        public bool $logOnly,
        public string $planCode,
        public string $capability,
        public ?int $limit,
        public int $usage,
        public bool $wouldBlock,
        public bool $enforced,
        public ?string $reason = null,
    ) {}

    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'log_only' => $this->logOnly,
            'plan_code' => $this->planCode,
            'capability' => $this->capability,
            'limit' => $this->limit,
            'usage' => $this->usage,
            'would_block' => $this->wouldBlock,
            'enforced' => $this->enforced,
            'reason' => $this->reason,
        ];
    }
}
