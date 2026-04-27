<?php

namespace App\Services\Billing;

use App\Models\Project;
use App\Models\User;
use InvalidArgumentException;

class BillingContextResolver
{
    public function fromUser(User $user): BillingContext
    {
        return new BillingContext(
            ownerType: 'user',
            ownerId: (int) $user->id,
            userId: (int) $user->id,
            source: 'api',
        );
    }

    public function fromProject(Project $project, ?User $actor = null): BillingContext
    {
        $metadata = [];

        if ($actor && (int) $actor->id !== (int) $project->user_id) {
            $metadata['actor_id'] = (int) $actor->id;
        }

        return new BillingContext(
            ownerType: 'user',
            ownerId: (int) $project->user_id,
            userId: (int) $project->user_id,
            projectId: (int) $project->id,
            source: 'api',
            metadata: $metadata,
        );
    }

    public function fromArray(array $context): BillingContext
    {
        $user = $context['user'] ?? null;
        $project = $context['project'] ?? null;

        if ($project instanceof Project) {
            return $this->withOverrides(
                $this->fromProject($project, $user instanceof User ? $user : null),
                $context
            );
        }

        if ($user instanceof User) {
            return $this->withOverrides($this->fromUser($user), $context);
        }

        $ownerId = $context['owner_id'] ?? $context['user_id'] ?? null;

        if (! $ownerId) {
            throw new InvalidArgumentException('Billing context requires user, project, owner_id, or user_id.');
        }

        return new BillingContext(
            ownerType: (string) ($context['owner_type'] ?? 'user'),
            ownerId: (int) $ownerId,
            userId: isset($context['user_id']) ? (int) $context['user_id'] : (int) $ownerId,
            projectId: isset($context['project_id']) ? (int) $context['project_id'] : null,
            source: $context['source'] ?? 'api',
            metadata: $context['metadata'] ?? [],
        );
    }

    private function withOverrides(BillingContext $resolved, array $context): BillingContext
    {
        return new BillingContext(
            ownerType: (string) ($context['owner_type'] ?? $resolved->ownerType),
            ownerId: (int) ($context['owner_id'] ?? $resolved->ownerId),
            userId: isset($context['user_id']) ? (int) $context['user_id'] : $resolved->userId,
            projectId: isset($context['project_id']) ? (int) $context['project_id'] : $resolved->projectId,
            source: $context['source'] ?? $resolved->source,
            metadata: array_merge($resolved->metadata, $context['metadata'] ?? []),
        );
    }
}
