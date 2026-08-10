<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Domain\Enums\SourceFileStatus;
use App\Domain\PriceIndices\Domain\Enums\StatisticalImportStatus;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesApiException;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesInvariantViolation;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class RetryStatisticalImport
{
    public function __construct(
        private readonly StatisticalImporterRegistry $registry,
        private readonly CreateStatisticalImport $create,
        private readonly DispatchStatisticalImportJob $dispatch,
    ) {
    }

    public function execute(StatisticalImport $failedImport, User $actor): StatisticalImport
    {
        $retry = DB::transaction(function () use ($failedImport, $actor): StatisticalImport {
            $failed = StatisticalImport::query()
                ->with(['dataset', 'sourceFile'])
                ->lockForUpdate()
                ->findOrFail($failedImport->id);

            if ($failed->status !== StatisticalImportStatus::Failed) {
                throw new PriceIndicesApiException(
                    'import_not_failed',
                    409,
                    'Only a failed import can be retried.',
                );
            }
            if ($failed->sourceFile->status !== SourceFileStatus::Active) {
                throw new PriceIndicesApiException(
                    'source_file_not_active',
                    409,
                    'The source file is no longer active.',
                );
            }

            try {
                $importer = $this->registry->forImport($failed);
            } catch (PriceIndicesInvariantViolation $exception) {
                throw new PriceIndicesApiException(
                    'importer_unavailable',
                    409,
                    'The importer used by the failed attempt is unavailable.',
                    $exception,
                );
            }

            $laterAttempt = StatisticalImport::query()
                ->where('source_file_id', $failed->source_file_id)
                ->where('importer_code', $importer->code())
                ->where('importer_version', $importer->version())
                ->where('attempt_no', '>', $failed->attempt_no)
                ->latest('attempt_no')
                ->first();
            if ($laterAttempt !== null) {
                throw new PriceIndicesApiException(
                    'successful_import_already_exists',
                    409,
                    'A later import attempt already exists for this source file.',
                );
            }

            return $this->create->execute(
                $failed->dataset,
                $failed->sourceFile,
                $actor,
                $failed,
            );
        });

        $this->dispatch->execute($retry);

        return $retry->refresh();
    }
}
