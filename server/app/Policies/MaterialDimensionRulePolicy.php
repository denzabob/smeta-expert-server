<?php

namespace App\Policies;

use App\Models\MaterialDimensionRule;
use App\Models\User;

class MaterialDimensionRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, MaterialDimensionRule $rule): bool
    {
        return $this->isAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, MaterialDimensionRule $rule): bool
    {
        return $this->isAdmin($user);
    }

    public function delete(User $user, MaterialDimensionRule $rule): bool
    {
        return $this->isAdmin($user);
    }

    private function isAdmin(User $user): bool
    {
        return (int) $user->id === 1;
    }
}
