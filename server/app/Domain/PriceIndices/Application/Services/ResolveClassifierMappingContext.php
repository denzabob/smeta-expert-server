<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifier;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierVersion;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierItemMappingException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class ResolveClassifierMappingContext
{
    /**
     * @return array{classifier: StatisticalClassifier, version: StatisticalClassifierVersion, aliases: list<string>}
     */
    public function execute(string $classifierCode, bool $lockActivePointer = false): array
    {
        $code = mb_strtolower(trim($classifierCode), 'UTF-8');
        $aliases = $this->compatibleAliases($code);

        if ($aliases === []) {
            throw new ClassifierItemMappingException(
                'classifier_mapping_not_supported',
                'The requested classifier is not configured for dataset-local mappings.'
            );
        }

        $classifier = StatisticalClassifier::query()->where('code', $code)->first();
        if ($classifier === null) {
            throw new ClassifierItemMappingException(
                'classifier_not_found',
                'The requested canonical classifier does not exist.'
            );
        }

        $pointer = DB::table('statistical_classifier_active_versions')
            ->where('classifier_id', $classifier->id)
            ->when($lockActivePointer, fn ($query) => $query->lockForUpdate())
            ->first();

        if ($pointer === null) {
            throw new ClassifierItemMappingException(
                'active_classifier_version_required',
                'An explicit active classifier version is required for mapping generation.'
            );
        }

        $version = StatisticalClassifierVersion::query()
            ->whereKey($pointer->classifier_version_id)
            ->where('classifier_id', $classifier->id)
            ->first();

        if ($version === null) {
            throw new ClassifierItemMappingException(
                'active_classifier_version_invalid',
                'The active classifier version pointer is invalid.'
            );
        }

        return compact('classifier', 'version', 'aliases');
    }

    /** @param list<string> $aliases */
    public function compatibleItemsQuery(array $aliases): Builder
    {
        return \App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierItem::query()
            ->join('statistical_datasets as mapping_datasets', 'mapping_datasets.id', '=', 'statistical_classifier_items.dataset_id')
            ->whereIn('mapping_datasets.classifier_code', $aliases)
            ->whereIn('statistical_classifier_items.classifier_code', $aliases)
            ->select('statistical_classifier_items.*');
    }

    /** @return list<string> */
    private function compatibleAliases(string $classifierCode): array
    {
        $configured = config('price_indices.classifier_mappings.dataset_classifier_aliases', []);
        if (! is_array($configured)) {
            return [];
        }

        $aliases = [];
        foreach ($configured as $alias => $canonicalCode) {
            if (is_string($alias) && $canonicalCode === $classifierCode) {
                $aliases[] = $alias;
            }
        }

        return array_values(array_unique($aliases));
    }
}
