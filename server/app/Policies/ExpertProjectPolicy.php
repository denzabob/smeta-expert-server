<?php
namespace App\Policies;
use App\Models\Expert\ExpertProject;
use App\Models\User;
class ExpertProjectPolicy
{
    public function viewAny(User $user): bool { return true; }
    public function create(User $user): bool { return true; }
    public function view(User $user, ExpertProject $project): bool { return (int) $project->user_id === (int) $user->id; }
    public function update(User $user, ExpertProject $project): bool { return $this->view($user, $project); }
    public function delete(User $user, ExpertProject $project): bool { return $this->view($user, $project); }
}
