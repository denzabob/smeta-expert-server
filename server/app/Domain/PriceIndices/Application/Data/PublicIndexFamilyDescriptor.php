<?php

namespace App\Domain\PriceIndices\Application\Data;

final readonly class PublicIndexFamilyDescriptor
{
    /**
     * @param  list<string>  $datasetCodes
     * @param  list<string>  $datasetCodePrefixes
     * @param  list<string>  $searchAliases
     * @param  array<string, string>  $metadataContext
     * @param  array<string, string>  $structuredDataContext
     * @param  list<string>  $okpd2ClassifierAliases
     * @param  array<string, string>  $semanticSlugs
     */
    public function __construct(
        public string $code,
        public array $datasetCodes,
        public array $datasetCodePrefixes,
        public string $publicLabel,
        public string $shortLabel,
        public string $landingPath,
        public string $detailPathPrefix,
        public string $searchLabel,
        public array $searchAliases,
        public array $metadataContext,
        public array $structuredDataContext,
        public array $okpd2ClassifierAliases,
        public array $semanticSlugs,
        public ?string $primaryItemCode,
    ) {}

    public function supportsDatasetCode(string $datasetCode): bool
    {
        if (in_array($datasetCode, $this->datasetCodes, true)) {
            return true;
        }

        foreach ($this->datasetCodePrefixes as $prefix) {
            if (str_starts_with($datasetCode, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function supportsOkpd2(string $classifierAlias): bool
    {
        return in_array($classifierAlias, $this->okpd2ClassifierAliases, true);
    }
}
