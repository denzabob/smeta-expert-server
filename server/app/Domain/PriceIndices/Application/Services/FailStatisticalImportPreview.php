<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Domain\Enums\StatisticalImportPreviewStatus;
use App\Domain\PriceIndices\Domain\Previews\StatisticalImportPreview;
use App\Domain\PriceIndices\Domain\Previews\StatisticalImportPreviewLifecycle;
use Illuminate\Support\Facades\DB;

final class FailStatisticalImportPreview
{
    public function __construct(private readonly StatisticalImportPreviewLifecycle $lifecycle)
    {
    }

    public function execute(
        StatisticalImportPreview $preview,
        string $failureCode,
        string $failureMessage,
    ): StatisticalImportPreview {
        return DB::transaction(function () use ($preview, $failureCode, $failureMessage): StatisticalImportPreview {
            $target = StatisticalImportPreview::query()->lockForUpdate()->findOrFail($preview->id);
            if (! in_array($target->status, [
                StatisticalImportPreviewStatus::Pending,
                StatisticalImportPreviewStatus::Running,
            ], true)) {
                return $target;
            }

            $this->lifecycle->transition($target, StatisticalImportPreviewStatus::Failed);
            $failedAt = now();
            $target->forceFill([
                'failure_code' => $failureCode,
                'failure_message' => $failureMessage,
                'failed_at' => $failedAt,
                'finished_at' => $failedAt,
                'expires_at' => $failedAt->copy()->addHours(
                    (int) config('price_indices.imports.preview_cache_ttl_hours', 24)
                ),
            ])->save();

            return $target->refresh();
        });
    }
}
