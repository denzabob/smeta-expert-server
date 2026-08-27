<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Application\Support\DecimalMath;
use App\Domain\PriceIndices\Application\Support\PublicIndexFormatter;
use App\Domain\PriceIndices\Application\ValueObjects\MonthlyPeriod;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesApiException;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Domain\PriceIndices\Domain\PublicPages\StatisticalPublicSeriesPage;
use App\Domain\PriceIndices\Domain\Series\StatisticalSeries;

final class CalculatePublicStatisticalIndex
{
    public function __construct(
        private readonly CalculateStatisticalIndexChain $calculator,
        private readonly DecimalMath $decimal,
        private readonly PublicIndexFormatter $formatter,
        private readonly PublicIndexFamilyRegistry $families,
    ) {}

    /** @return array<string, mixed> */
    public function execute(
        string $familyCode,
        string $slug,
        string $startPeriod,
        string $endPeriod,
        ?string $amount,
    ): array {
        $page = StatisticalPublicSeriesPage::query()
            ->where('slug', $slug)
            ->where('is_indexable', true)
            ->first();
        if ($page === null) {
            throw new PriceIndicesApiException(
                'public_series_not_available',
                404,
                'The published statistical series is not available.'
            );
        }

        $page->loadMissing('dataset:id,code,name,provider_name');
        $family = $this->families->forDataset($page->dataset);
        if ($family->code !== $familyCode) {
            throw new PriceIndicesApiException(
                'public_series_not_available',
                404,
                'The published statistical series is not available.'
            );
        }

        $series = $page->series()
            ->with([
                'classifierItem:id,public_id,classifier_code,item_code,name,metadata_json',
                'indicator:id,code,name',
                'territory:id,code,name',
            ])
            ->first();
        $import = $page->import()
            ->with([
                'dataset:id,public_id,code,name,provider_name',
                'sourceFile:id,public_id,source_id,original_filename,sha256,source_url',
                'sourceFile.source:id,source_page_url',
            ])
            ->first();

        if (! $series instanceof StatisticalSeries
            || ! $import instanceof StatisticalImport
            || $series->dataset_id !== $page->dataset_id
            || $import->dataset_id !== $page->dataset_id
            || $page->period_from === null
            || $page->period_to === null
        ) {
            throw new PriceIndicesApiException(
                'public_snapshot_unavailable',
                409,
                'The public snapshot cannot be calculated safely.'
            );
        }

        $start = MonthlyPeriod::parse($startPeriod);
        $end = MonthlyPeriod::parse($endPeriod);
        $span = (($end->year - $start->year) * 12) + ($end->month - $start->month);
        if ($span > (int) config('price_indices.public_calculation.max_period_months', 120)) {
            throw new PriceIndicesApiException(
                'period_too_long',
                422,
                'The selected period is too long for the public calculation.'
            );
        }

        $series->setAttribute('period_from', $page->period_from->format('Y-m-d'));
        $series->setAttribute('period_to', $page->period_to->format('Y-m-d'));
        $calculation = $this->calculator->execute(
            $import,
            $series,
            $startPeriod,
            $endPeriod,
            $amount,
        );

        $changeRaw = $this->decimal->multiply(
            $this->decimal->subtract($calculation['coefficient_raw'], '1'),
            '100'
        );
        $sourceFile = $import->sourceFile;
        $sourceUrl = $sourceFile?->source?->source_page_url ?: $sourceFile?->source_url;
        if (! is_string($sourceUrl) || filter_var($sourceUrl, FILTER_VALIDATE_URL) === false) {
            $sourceUrl = null;
        }
        $item = $series->classifierItem;
        $providerCodeKind = $item?->metadata_json['provider_code_kind'] ?? null;
        $provider = $import->dataset?->provider_name ?: 'Росстат';

        return [
            'page' => [
                'slug' => $page->slug,
                'title' => $item?->name,
                'classifier' => [
                    'code' => $family->code === PublicIndexFamilyRegistry::PRODUCER_PRICES ? $item?->item_code : null,
                    'type' => $family->supportsOkpd2((string) $item?->classifier_code)
                        ? $this->formatter->classifierLabel(
                            (string) $item?->classifier_code,
                            is_string($providerCodeKind) ? $providerCodeKind : null,
                        )
                        : null,
                ],
                'family' => ['code' => $family->code, 'label' => $family->searchLabel],
                'series_type' => $this->formatter->indicatorType(
                    (string) $series->indicator?->name,
                    $provider,
                ),
            ],
            'period' => $calculation['period'],
            'coefficient' => $calculation['coefficient'],
            'change_percent' => $this->decimal->roundHalfUp($changeRaw, 2),
            'amount' => $calculation['amount'] === null ? null : [
                'original' => $calculation['amount']['base'],
                'adjusted' => $calculation['amount']['adjusted'],
            ],
            'chain' => array_map(static fn (array $factor): array => [
                'period' => $factor['period'],
                'index' => $factor['index'],
                'factor' => $factor['factor'],
                'running_coefficient' => $factor['running_coefficient'],
            ], $calculation['chain']),
            'provenance' => [
                'provider' => $provider,
                'dataset' => ['name' => $import->dataset?->name],
                'publication' => [
                    'reference' => $import->public_id,
                    'published_at' => $import->published_at?->toISOString(),
                ],
                'source' => [
                    'reference' => $sourceFile?->public_id,
                    'filename' => $sourceFile?->original_filename,
                    'sha256' => $sourceFile?->sha256,
                    'url' => $sourceUrl,
                ],
                'snapshot' => [
                    'reference' => $page->public_id,
                    'generated_at' => $page->generated_at?->toISOString(),
                    'period_from' => $page->period_from->format('Y-m'),
                    'period_to' => $page->period_to->format('Y-m'),
                ],
            ],
        ];
    }
}
