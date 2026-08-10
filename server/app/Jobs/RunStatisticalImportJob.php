<?php

namespace App\Jobs;

use App\Domain\PriceIndices\Application\Services\BeginImportValidation;
use App\Domain\PriceIndices\Application\Services\CleanupFailedStatisticalImport;
use App\Domain\PriceIndices\Application\Services\FailStatisticalImport;
use App\Domain\PriceIndices\Application\Services\MarkImportReadyForPublish;
use App\Domain\PriceIndices\Application\Services\StartStatisticalImport;
use App\Domain\PriceIndices\Application\Services\StatisticalImporterRegistry;
use App\Domain\PriceIndices\Domain\Enums\StatisticalImportStatus;
use App\Domain\PriceIndices\Domain\Exceptions\StatisticalImportParsingFailed;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunStatisticalImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;
    public int $timeout;
    public int $backoff;

    public function __construct(public readonly string $importPublicId)
    {
        $this->tries = (int) config('price_indices.imports.job_tries', 1);
        $this->timeout = (int) config('price_indices.imports.job_timeout', 3_600);
        $this->backoff = (int) config('price_indices.imports.job_backoff', 60);
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('price-indices-import:'.$this->importPublicId))
            ->expireAfter($this->timeout + 60)
            ->dontRelease()];
    }

    public function handle(
        StartStatisticalImport $start,
        StatisticalImporterRegistry $registry,
        BeginImportValidation $beginValidation,
        MarkImportReadyForPublish $markReady,
        CleanupFailedStatisticalImport $cleanup,
        FailStatisticalImport $fail,
    ): void {
        $import = StatisticalImport::query()->where('public_id', $this->importPublicId)->firstOrFail();
        if ($import->status !== StatisticalImportStatus::Pending) {
            Log::warning('Price indices import job skipped because import is not pending.', $this->context($import));
            return;
        }

        Log::info('Price indices import started.', $this->context($import));
        try {
            $import = $start->execute($import);
            $registry->forImport($import)->import($import);
            $import = $beginValidation->execute($import->refresh());
            Log::info('Price indices import validation started.', $this->context($import));
            $import = $markReady->execute($import);
            Log::info('Price indices import ready for publication.', $this->context($import));
        } catch (StatisticalImportParsingFailed $exception) {
            $this->markFailed($import, $exception->failureCode, $exception->getMessage(), $cleanup, $fail);
        } catch (Throwable $exception) {
            $this->markFailed($import, 'unexpected_import_error', 'Unexpected statistical import failure.', $cleanup, $fail);
            Log::error('Price indices import failed unexpectedly.', $this->context($import) + [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
            throw $exception;
        }
    }

    private function markFailed(
        StatisticalImport $import,
        string $code,
        string $message,
        CleanupFailedStatisticalImport $cleanup,
        FailStatisticalImport $fail,
    ): void {
        $import = $import->refresh();
        if (in_array($import->status, [StatisticalImportStatus::Importing, StatisticalImportStatus::Validating], true)) {
            $cleanup->execute($import);
            $import = $fail->execute($import, $code, $message);
        }
        Log::warning('Price indices import failed.', $this->context($import) + ['failure_code' => $code]);
    }

    /** @return array<string, mixed> */
    private function context(StatisticalImport $import): array
    {
        return [
            'import_public_id' => $import->public_id,
            'source_file_public_id' => $import->sourceFile()->value('public_id'),
            'dataset_code' => $import->dataset()->value('code'),
            'importer_code' => $import->importer_code,
            'importer_version' => $import->importer_version,
        ];
    }
}
