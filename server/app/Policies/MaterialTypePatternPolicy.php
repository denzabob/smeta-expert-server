<?php

namespace App\Policies;

use App\Models\MaterialTypePattern;
use App\Models\User;

class MaterialTypePatternPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, MaterialTypePattern $pattern): bool
    {
        return $this->isAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, MaterialTypePattern $pattern): bool
    {
        return $this->isAdmin($user);
    }

    public function delete(User $user, MaterialTypePattern $pattern): bool
    {
        return $this->isAdmin($user);
    }

    private function isAdmin(User $user): bool
    {
        return (int) $user->id === 1;
    }
}
