<?php

namespace App\Observers;

use Illuminate\Database\Eloquent\Model;

final class StorageCascadeReferenceObserver
{
    public function deleted(Model $model): void
    {
        $owner = $model->getAttribute('user_id') ?? $model->getAttribute('created_by');
        app(\App\Services\Storage\StorageCascadeReferences::class)->prune($owner ? (int) $owner : null);
    }
}
