<?php

namespace App\Services;

use App\Models\Operation;

class OperationAccessService
{
    public function ensureReadable(Operation $operation, int $userId): void
    {
        if ($operation->user_id !== null && (int) $operation->user_id !== $userId) {
            abort(404);
        }
    }

    public function ensureWritable(Operation $operation, int $userId): void
    {
        if ((int) $operation->user_id !== $userId) {
            abort(403);
        }
    }
}
