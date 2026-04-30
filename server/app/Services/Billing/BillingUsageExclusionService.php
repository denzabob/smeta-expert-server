<?php

namespace App\Services\Billing;

use App\Models\User;

class BillingUsageExclusionService
{
    public function shouldIgnoreUser(?User $user): bool
    {
        return $user !== null && $this->shouldIgnoreUserId((int) $user->id);
    }

    public function shouldIgnoreUserId(?int $userId): bool
    {
        return $userId === 1;
    }
}
