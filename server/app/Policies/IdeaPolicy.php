<?php

namespace App\Policies;

use App\Models\Idea;
use App\Models\User;

class IdeaPolicy
{
    public function updateStatus(User $user, Idea $idea): bool
    {
        return $this->isAdmin($user);
    }

    public function delete(User $user, Idea $idea): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        return (int) $idea->user_id === (int) $user->id
            && $idea->status === Idea::STATUS_NEW;
    }

    public function moderateComments(User $user, Idea $idea): bool
    {
        return $this->isAdmin($user) || (int) $idea->user_id === (int) $user->id;
    }

    private function isAdmin(User $user): bool
    {
        return (int) $user->id === 1;
    }
}
