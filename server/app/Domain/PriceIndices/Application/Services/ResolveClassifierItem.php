<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Application\Data\ResolvedClassifierItem;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierItem;
use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use Illuminate\Database\QueryException;

class ResolveClassifierItem
{
    public function __construct(private readonly StatisticalNameNormalizer $normalizer)
    {
    }

    public function execute(
        StatisticalDataset $dataset,
        string $classifierCode,
        string $itemCode,
        string $name
    ): ResolvedClassifierItem {
        $identity = [
            'dataset_id' => $dataset->getKey(),
            'classifier_code' => $classifierCode,
            'item_code' => $itemCode,
        ];
        $normalizedName = $this->normalizer->normalize($name);
        $item = StatisticalClassifierItem::query()->where($identity)->first();

        if ($item === null) {
            try {
                $item = StatisticalClassifierItem::query()->create($identity + [
                    'name' => trim($name),
                    'normalized_name' => $normalizedName,
                ]);
            } catch (QueryException $exception) {
                if (($exception->errorInfo[1] ?? null) !== 1062) {
                    throw $exception;
                }

                $item = StatisticalClassifierItem::query()->where($identity)->sole();
            }
        }

        return new ResolvedClassifierItem(
            $item,
            $item->normalized_name !== $normalizedName
        );
    }
}
