<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesApiException;
use App\Domain\PriceIndices\Domain\Previews\StatisticalImportPreview;
use App\Jobs\RunStatisticalImportPreviewJob;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DispatchStatisticalImportPreviewJob
{
    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly FailStatisticalImportPreview $fail,
    ) {
    }

    public function execute(StatisticalImportPreview $preview): void
    {
        try {
            $this->dispatcher->dispatch(new RunStatisticalImportPreviewJob($preview->public_id));
        } catch (Throwable $exception) {
            $this->fail->execute(
                $preview,
                'job_dispatch_failed',
                'The statistical preview job could not be queued.',
            );
            Log::error('Price indices preview job dispatch failed.', [
                'preview_public_id' => $preview->public_id,
                'exception' => $exception::class,
            ]);

            throw new PriceIndicesApiException(
                'job_dispatch_failed',
                503,
                'The statistical preview job could not be queued.',
                $exception,
            );
        }
    }
}
