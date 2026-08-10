<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Domain\Enums\StatisticalImportPreviewStatus;
use App\Domain\PriceIndices\Domain\Previews\StatisticalImportPreview;
use App\Domain\PriceIndices\Domain\Previews\StatisticalImportPreviewLifecycle;
use Illuminate\Support\Facades\DB;

final class ExpireStatisticalImportPreviewIfNeeded
{
    public function __construct(private readonly StatisticalImportPreviewLifecycle $lifecycle)
    {
    }

    public function execute(StatisticalImportPreview $preview): StatisticalImportPreview
    {
        if (! in_array($preview->status, [
            StatisticalImportPreviewStatus::Ready,
            StatisticalImportPreviewStatus::Failed,
        ], true) || $preview->expires_at === null || $preview->expires_at->isFuture()) {
            return $preview;
        }

        return DB::transaction(function () use ($preview): StatisticalImportPreview {
            $target = StatisticalImportPreview::query()->lockForUpdate()->findOrFail($preview->id);
            if (in_array($target->status, [
                StatisticalImportPreviewStatus::Ready,
                StatisticalImportPreviewStatus::Failed,
            ], true) && $target->expires_at !== null && $target->expires_at->isPast()) {
                $this->lifecycle->transition($target, StatisticalImportPreviewStatus::Expired);
                $target->save();
            }

            return $target->refresh();
        });
    }
}
