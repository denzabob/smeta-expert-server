<?php

namespace App\Services;

use App\Models\OperationPriceSource;
use Illuminate\Support\Facades\DB;

class OperationPriceSourceService
{
    public function getAllForOperation(int $operationId)
    {
        return OperationPriceSource::query()
            ->where('operation_id', $operationId)
            ->orderByDesc('is_active')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    public function getActiveForOperation(int $operationId): ?OperationPriceSource
    {
        return OperationPriceSource::query()
            ->active()
            ->where('operation_id', $operationId)
            ->latest('id')
            ->first();
    }

    public function activate(int $sourceId): OperationPriceSource
    {
        $source = OperationPriceSource::query()->findOrFail($sourceId);

        return $source->activate();
    }

    public function create(array $data): OperationPriceSource
    {
        return DB::transaction(function () use ($data) {
            $operationId = (int) $data['operation_id'];
            $hasAnySources = OperationPriceSource::query()
                ->where('operation_id', $operationId)
                ->exists();

            $source = OperationPriceSource::query()->create([
                ...$data,
                'is_active' => !$hasAnySources,
            ]);

            if (!$hasAnySources) {
                return $source->activate();
            }

            return $source->fresh();
        });
    }

    public function delete(int $sourceId): void
    {
        DB::transaction(function () use ($sourceId) {
            $source = OperationPriceSource::query()->findOrFail($sourceId);
            $operationId = (int) $source->operation_id;
            $wasActive = (bool) $source->is_active;

            $source->delete();

            if (!$wasActive) {
                return;
            }

            $nextSource = OperationPriceSource::query()
                ->where('operation_id', $operationId)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

            if ($nextSource) {
                $nextSource->activate();
            }
        });
    }
}
