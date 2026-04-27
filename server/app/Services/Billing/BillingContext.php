<?php

namespace App\Services\Billing;

class BillingContext
{
    public function __construct(
        public readonly string $ownerType,
        public readonly int $ownerId,
        public readonly ?int $userId = null,
        public readonly ?int $projectId = null,
        public readonly ?string $source = null,
        public readonly array $metadata = [],
    ) {}
}
