<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Domain\Enums\SourceFileStatus;
use App\Domain\PriceIndices\Domain\Enums\StatisticalImportPreviewStatus;
use App\Domain\PriceIndices\Domain\Enums\StatisticalImportStatus;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesApiException;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesInvariantViolation;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Domain\PriceIndices\Domain\Previews\StatisticalImportPreview;
use App\Domain\PriceIndices\Domain\SourceFiles\StatisticalSourceFile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class QueueStatisticalImport
{
    public function __construct(
        private readonly StatisticalImporterRegistry $registry,
        private readonly CreateStatisticalImport $create,
        private readonly DispatchStatisticalImportJob $dispatch,
        private readonly ExpireStatisticalImportPreviewIfNeeded $expirePreview,
    ) {}

    public function execute(
        StatisticalSourceFile $sourceFile,
        User $actor,
        string $previewPublicId,
    ): StatisticalImport {
        $preview = StatisticalImportPreview::query()
            ->where('public_id', $previewPublicId)
            ->first();

        if ($preview === null) {
            throw new PriceIndicesApiException(
                'preview_not_found',
                404,
                'The requested preliminary analysis was not found.',
            );
        }

        $preview = $this->expirePreview->execute($preview);

        $import = DB::transaction(function () use ($sourceFile, $actor, $preview): StatisticalImport {
            $file = StatisticalSourceFile::query()
                ->with('dataset')
                ->lockForUpdate()
                ->findOrFail($sourceFile->id);

            $preview = StatisticalImportPreview::query()
                ->lockForUpdate()
                ->findOrFail($preview->id);

            $this->assertPreviewMatchesSourceFile($preview, $file);

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

            if ($preview->importer_code !== $importer->code()
                || $preview->importer_version !== $importer->version()
            ) {
                throw new PriceIndicesApiException(
                    'preview_importer_mismatch',
                    409,
                    'The preliminary analysis was produced by a different importer version.',
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

    private function assertPreviewMatchesSourceFile(
        StatisticalImportPreview $preview,
        StatisticalSourceFile $sourceFile,
    ): void {
        if ($preview->source_file_id !== $sourceFile->id) {
            throw new PriceIndicesApiException(
                'preview_source_file_mismatch',
                409,
                'The preliminary analysis belongs to a different source file.',
                details: [
                    'requested_source_file_public_id' => $sourceFile->public_id,
                    'preview_source_file_public_id' => $preview->sourceFile()->value('public_id'),
                ],
            );
        }

        if ($preview->status === StatisticalImportPreviewStatus::Ready
            && $preview->expires_at !== null
            && $preview->expires_at->isPast()
        ) {
            throw new PriceIndicesApiException(
                'preview_expired',
                409,
                'The preliminary analysis has expired.',
            );
        }

        if ($preview->status !== StatisticalImportPreviewStatus::Ready || $preview->result_json === null) {
            $code = match ($preview->status) {
                StatisticalImportPreviewStatus::Failed => 'preview_failed',
                StatisticalImportPreviewStatus::Expired => 'preview_expired',
                default => 'preview_not_ready',
            };

            throw new PriceIndicesApiException(
                $code,
                409,
                'The preliminary analysis is not ready for import.',
            );
        }

        $resultSourceFilePublicId = data_get($preview->result_json, 'source_file.public_id');
        if ($resultSourceFilePublicId !== $sourceFile->public_id) {
            throw new PriceIndicesApiException(
                'preview_source_file_mismatch',
                409,
                'The preliminary analysis result belongs to a different source file.',
                details: [
                    'requested_source_file_public_id' => $sourceFile->public_id,
                    'preview_source_file_public_id' => $resultSourceFilePublicId,
                ],
            );
        }
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
