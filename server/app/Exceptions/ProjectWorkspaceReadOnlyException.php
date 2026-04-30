<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class ProjectWorkspaceReadOnlyException extends RuntimeException
{
    public function __construct(private readonly array $status)
    {
        parent::__construct((string) ($status['message'] ?? 'Доступен режим просмотра. Лимит проектов на текущем тарифе превышен.'));
    }

    public function render($request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'billing' => [
                'reason' => $this->status['reason'] ?? 'active_projects_limit_exceeded',
                'limit_key' => $this->status['limit_key'] ?? null,
                'owned_projects' => $this->status['owned_projects'] ?? $this->status['active_projects'] ?? 0,
                'active_projects' => $this->status['active_projects'] ?? 0,
                'limit' => $this->status['limit'] ?? null,
                'read_only' => (bool) ($this->status['read_only'] ?? true),
            ],
        ], 423);
    }
}
