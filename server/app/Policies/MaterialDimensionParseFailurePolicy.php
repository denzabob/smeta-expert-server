<?php

namespace App\Policies;

use App\Models\MaterialDimensionParseFailure;
use App\Models\User;

class MaterialDimensionParseFailurePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, MaterialDimensionParseFailure $failure): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, MaterialDimensionParseFailure $failure): bool
    {
        return $this->isAdmin($user);
    }

    private function isAdmin(User $user): bool
    {
        return (int) $user->id === 1;
    }
}
