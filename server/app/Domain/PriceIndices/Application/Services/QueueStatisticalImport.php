<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Domain\Enums\SourceFileStatus;
use App\Domain\PriceIndices\Domain\Enums\StatisticalImportStatus;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesApiException;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesInvariantViolation;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Domain\PriceIndices\Domain\SourceFiles\StatisticalSourceFile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class QueueStatisticalImport
{
    public function __construct(
        private readonly StatisticalImporterRegistry $registry,
        private readonly CreateStatisticalImport $create,
        private readonly DispatchStatisticalImportJob $dispatch,
    ) {
    }

    public function execute(StatisticalSourceFile $sourceFile, User $actor): StatisticalImport
    {
        $import = DB::transaction(function () use ($sourceFile, $actor): StatisticalImport {
            $file = StatisticalSourceFile::query()
                ->with('dataset')
                ->lockForUpdate()
                ->findOrFail($sourceFile->id);

            if ($file->status !== SourceFileStatus::Active) {
                throw new PriceIndicesApiException(
                    'source_file_not_active',
                    409,
                    'Only an active source file can be imported.',
                );
            }

            try {
                $importer = $this->registry->forSourceFile($file);
            } catch (PriceIndicesInvariantViolation $exception) {
                throw new PriceIndicesApiException(
                    'unsupported_dataset',
                    422,
                    'No importer supports this source file dataset.',
                    $exception,
                );
            }

            $existing = StatisticalImport::query()
                ->where('source_file_id', $file->id)
                ->where('importer_code', $importer->code())
                ->where('importer_version', $importer->version())
                ->latest('attempt_no')
                ->first();

            if ($existing !== null) {
                $this->rejectDuplicate($existing);
            }

            return $this->create->execute($file->dataset, $file, $actor);
        });

        $this->dispatch->execute($import);

        return $import->refresh();
    }

    private function rejectDuplicate(StatisticalImport $import): never
    {
        [$code, $message] = match ($import->status) {
            StatisticalImportStatus::Pending,
            StatisticalImportStatus::Importing,
            StatisticalImportStatus::Validating => [
                'import_already_running', 'An import for this source file is already running.',
            ],
            StatisticalImportStatus::ReadyForPublish => [
                'import_already_ready', 'An import for this source file is already ready for publication.',
            ],
            StatisticalImportStatus::Published => [
                'import_already_published', 'This source file has already been published.',
            ],
            StatisticalImportStatus::Superseded => [
                'import_already_completed', 'This source file has already completed an import.',
            ],
            StatisticalImportStatus::Failed => [
                'import_retry_required', 'Failed imports must be retried through the retry endpoint.',
            ],
        };

        throw new PriceIndicesApiException($code, 409, $message);
    }
}
