<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesApiException;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Jobs\RunStatisticalImportJob;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DispatchStatisticalImportJob
{
    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly FailStatisticalImport $fail,
    ) {
    }

    public function execute(StatisticalImport $import): void
    {
        try {
            $this->dispatcher->dispatch(new RunStatisticalImportJob($import->public_id));
        } catch (Throwable $exception) {
            $this->fail->execute(
                $import,
                'job_dispatch_failed',
                'The statistical import job could not be queued.',
            );
            Log::error('Price indices import job dispatch failed.', [
                'import_public_id' => $import->public_id,
                'exception' => $exception::class,
            ]);

            throw new PriceIndicesApiException(
                'job_dispatch_failed',
                503,
                'The statistical import job could not be queued.',
                $exception,
            );
        }
    }
}
