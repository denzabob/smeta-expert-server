<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Enums\SourceFileStatus;
use App\Domain\PriceIndices\Domain\Enums\StatisticalImportStatus;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesInvariantViolation;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Domain\PriceIndices\Domain\SourceFiles\StatisticalSourceFile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateStatisticalImport
{
    public function execute(
        StatisticalDataset $dataset,
        StatisticalSourceFile $sourceFile,
        ?User $actor = null,
        ?StatisticalImport $retryOf = null
    ): StatisticalImport {
        $identity = config('price_indices.imports.importers.producer_price_indices_by_product');
        $code = is_array($identity) ? ($identity['code'] ?? null) : null;
        $version = is_array($identity) ? ($identity['version'] ?? null) : null;

        if (! is_string($code) || $code === '' || ! is_string($version) || $version === '') {
            throw new PriceIndicesInvariantViolation('Statistical importer identity is not configured.');
        }

        if ($sourceFile->dataset_id !== $dataset->id
            || $sourceFile->status !== SourceFileStatus::Active
        ) {
            throw new PriceIndicesInvariantViolation(
                'A statistical import can only be created for an active source file from its dataset.'
            );
        }

        if ($retryOf !== null
            && ($retryOf->status !== StatisticalImportStatus::Failed
                || $retryOf->source_file_id !== $sourceFile->id
                || $retryOf->importer_code !== $code
                || $retryOf->importer_version !== $version)
        ) {
            throw new PriceIndicesInvariantViolation(
                'A retry must reference a failed import with the same source file and importer identity.'
            );
        }

        return DB::transaction(function () use (
            $dataset,
            $sourceFile,
            $actor,
            $retryOf,
            $code,
            $version
        ): StatisticalImport {
            $lastAttempt = StatisticalImport::query()
                ->where('source_file_id', $sourceFile->id)
                ->where('importer_code', $code)
                ->where('importer_version', $version)
                ->lockForUpdate()
                ->max('attempt_no');

            return StatisticalImport::query()->create([
                'dataset_id' => $dataset->id,
                'source_file_id' => $sourceFile->id,
                'importer_code' => $code,
                'importer_version' => $version,
                'attempt_no' => ((int) $lastAttempt) + 1,
                'retry_of_import_id' => $retryOf?->id,
                'status' => StatisticalImportStatus::Pending,
                'initiated_by_user_id' => $actor?->id,
            ]);
        });
    }
}
