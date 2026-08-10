<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Application\Data\ImportPreviewResult;
use App\Domain\PriceIndices\Domain\Enums\SourceFileStatus;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesApiException;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesInvariantViolation;
use App\Domain\PriceIndices\Domain\Exceptions\StatisticalImportParsingFailed;
use App\Domain\PriceIndices\Domain\SourceFiles\StatisticalSourceFile;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PreviewStatisticalSourceFile
{
    public function __construct(private readonly StatisticalImporterRegistry $registry)
    {
    }

    public function execute(StatisticalSourceFile $sourceFile): ImportPreviewResult
    {
        if ($sourceFile->status !== SourceFileStatus::Active) {
            throw new PriceIndicesApiException(
                'source_file_not_active',
                409,
                'Only an active source file can be previewed.',
            );
        }

        try {
            $importer = $this->registry->forSourceFile($sourceFile);
        } catch (PriceIndicesInvariantViolation $exception) {
            throw new PriceIndicesApiException(
                'unsupported_dataset',
                422,
                'No importer supports this source file dataset.',
                $exception,
            );
        }

        try {
            $preview = $importer->preview($sourceFile);
        } catch (StatisticalImportParsingFailed $exception) {
            if ($exception->failureCode === 'source_file_missing') {
                throw new PriceIndicesApiException(
                    'source_file_missing',
                    404,
                    'Source file binary was not found.',
                    $exception,
                );
            }

            throw new PriceIndicesApiException(
                'unsupported_workbook',
                422,
                'The workbook cannot be previewed by this importer.',
                $exception,
            );
        } catch (Throwable $exception) {
            Log::error('Price indices source file preview failed.', [
                'source_file_public_id' => $sourceFile->public_id,
                'dataset_public_id' => $sourceFile->dataset()->value('public_id'),
                'exception' => $exception::class,
            ]);

            throw new PriceIndicesApiException(
                'preview_failed',
                500,
                'Unable to preview the source file.',
                $exception,
            );
        }

        if ((int) ($preview->counts['fatal_errors'] ?? 0) > 0) {
            throw new PriceIndicesApiException(
                'unsupported_workbook',
                422,
                'The workbook contains fatal structural errors.',
            );
        }

        return $preview;
    }
}
