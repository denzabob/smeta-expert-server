<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Application\Data\StatisticalImportPublicationResult;
use App\Domain\PriceIndices\Domain\Enums\StatisticalImportStatus;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesApiException;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesInvariantViolation;
use App\Domain\PriceIndices\Domain\Exceptions\StatisticalImportConflict;
use App\Domain\PriceIndices\Domain\Exceptions\StatisticalImportTransitionNotAllowed;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Jobs\RefreshPriceIndicesPublicPagesJob;
use App\Models\User;

final class PublishStatisticalImportForAdmin
{
    public function __construct(private readonly PublishStatisticalImport $publish) {}

    public function execute(StatisticalImport $import, User $actor): StatisticalImportPublicationResult
    {
        $status = $import->status;
        if ($status !== StatisticalImportStatus::ReadyForPublish) {
            [$code, $message] = match ($status) {
                StatisticalImportStatus::Published => [
                    'import_already_published', 'The statistical import is already published.',
                ],
                StatisticalImportStatus::Superseded => [
                    'import_superseded', 'A superseded statistical import cannot be published.',
                ],
                default => ['import_not_ready', 'The statistical import is not ready for publication.'],
            };
            throw new PriceIndicesApiException($code, 409, $message);
        }

        try {
            $published = $this->publish->execute($import, $actor);
        } catch (StatisticalImportConflict $exception) {
            throw new PriceIndicesApiException(
                'publication_conflict',
                409,
                'The active statistical import changed concurrently.',
                $exception,
            );
        } catch (PriceIndicesInvariantViolation|StatisticalImportTransitionNotAllowed $exception) {
            throw new PriceIndicesApiException(
                'dataset_mismatch',
                409,
                'The import cannot be published for this dataset and source file state.',
                $exception,
            );
        }

        $published->loadMissing('dataset:id,public_id');
        RefreshPriceIndicesPublicPagesJob::dispatch(
            (string) $published->dataset->public_id,
            (string) $published->public_id,
        );

        return new StatisticalImportPublicationResult(
            $published,
            $published->supersedes()->value('public_id'),
        );
    }
}
