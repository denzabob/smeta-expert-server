<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Application\Data\PublicIndexFamilyDescriptor;
use App\Domain\PriceIndices\Application\Support\PublicIndexSlug;
use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use InvalidArgumentException;

final class PublicIndexFamilyRegistry
{
    public const PRODUCER_PRICES = 'producer_prices';

    public const CONSUMER_PRICES = 'consumer_prices';

    /** @var array<string, PublicIndexFamilyDescriptor> */
    private array $families;

    public function __construct(private readonly PublicIndexSlug $slugs)
    {
        $this->families = [
            self::PRODUCER_PRICES => new PublicIndexFamilyDescriptor(
                code: self::PRODUCER_PRICES,
                datasetCodes: ['producer_price_indices_by_product'],
                datasetCodePrefixes: ['producer_price_indices_'],
                publicLabel: 'Индексы цен производителей',
                shortLabel: 'ИЦП',
                landingPath: '/producer-prices/',
                detailPathPrefix: '',
                searchLabel: 'Индекс цен производителей',
                searchAliases: ['ицп', 'индекс цен производителей', 'индексы цен производителей'],
                metadataContext: [
                    'detail_title_suffix' => 'индекс цен производителей',
                    'detail_description_subject' => 'индекс цен производителей',
                ],
                structuredDataContext: [
                    'variable_name' => 'Индекс цен производителей',
                    'measurement_technique' => 'Индекс цен производителей к предыдущему месяцу',
                ],
                okpd2ClassifierAliases: ['okpd2', 'okpd2_based'],
                semanticSlugs: [],
                primaryItemCode: null,
            ),
            self::CONSUMER_PRICES => new PublicIndexFamilyDescriptor(
                code: self::CONSUMER_PRICES,
                datasetCodes: ['consumer_price_indices_rf_monthly'],
                datasetCodePrefixes: [],
                publicLabel: 'Индексы потребительских цен',
                shortLabel: 'ИПЦ',
                landingPath: '/consumer-prices/',
                detailPathPrefix: '/consumer-prices',
                searchLabel: 'Индекс потребительских цен',
                searchAliases: ['ипц', 'индекс потребительских цен', 'индексы потребительских цен', 'инфляция'],
                metadataContext: [
                    'detail_title_suffix' => 'индекс потребительских цен',
                    'detail_description_subject' => 'индекс потребительских цен',
                ],
                structuredDataContext: [
                    'variable_name' => 'Индекс потребительских цен',
                    'measurement_technique' => 'Индекс потребительских цен к предыдущему месяцу',
                ],
                okpd2ClassifierAliases: [],
                semanticSlugs: [
                    'all_items_and_services' => 'all-items-and-services',
                    'food_products' => 'food-products',
                    'non_food_products' => 'non-food-products',
                    'services' => 'services',
                ],
                primaryItemCode: 'all_items_and_services',
            ),
        ];
    }

    /** @return list<PublicIndexFamilyDescriptor> */
    public function all(): array
    {
        return array_values($this->families);
    }

    public function get(string $familyCode): PublicIndexFamilyDescriptor
    {
        return $this->families[$familyCode]
            ?? throw new InvalidArgumentException("Unknown public price index family: {$familyCode}.");
    }

    public function findForDataset(StatisticalDataset|string $dataset): ?PublicIndexFamilyDescriptor
    {
        $datasetCode = $dataset instanceof StatisticalDataset ? $dataset->code : $dataset;

        foreach ($this->families as $family) {
            if ($family->supportsDatasetCode($datasetCode)) {
                return $family;
            }
        }

        return null;
    }

    public function forDataset(StatisticalDataset|string $dataset): PublicIndexFamilyDescriptor
    {
        $family = $this->findForDataset($dataset);
        if ($family !== null) {
            return $family;
        }

        $datasetCode = $dataset instanceof StatisticalDataset ? $dataset->code : $dataset;

        throw new InvalidArgumentException("Dataset {$datasetCode} has no public price index family.");
    }

    public function slugForDataset(StatisticalDataset|string $dataset, string $itemCode): ?string
    {
        $family = $this->findForDataset($dataset);
        if ($family === null) {
            return null;
        }

        if ($family->semanticSlugs !== []) {
            return $family->semanticSlugs[$itemCode] ?? null;
        }

        return $this->slugs->fromItemCode($itemCode);
    }

    public function matchingSearchAlias(string $normalizedQuery): ?PublicIndexFamilyDescriptor
    {
        foreach ($this->families as $family) {
            if (in_array($normalizedQuery, $family->searchAliases, true)) {
                return $family;
            }
        }

        return null;
    }

    /** @return array{sql:string, bindings:list<string>} */
    public function datasetSql(PublicIndexFamilyDescriptor $family, string $column): array
    {
        $conditions = [];
        $bindings = [];

        foreach ($family->datasetCodes as $code) {
            $conditions[] = "{$column} = ?";
            $bindings[] = $code;
        }
        foreach ($family->datasetCodePrefixes as $prefix) {
            $conditions[] = "{$column} LIKE ?";
            $bindings[] = $prefix.'%';
        }

        return [
            'sql' => '('.implode(' OR ', $conditions).')',
            'bindings' => $bindings,
        ];
    }
}
