<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Application\Data\ClassifierItemMappingGenerationResult;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierItem;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierItemMapping;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierNode;
use App\Domain\PriceIndices\Domain\Enums\ClassifierItemMappingReviewStatus;
use App\Domain\PriceIndices\Domain\Enums\ClassifierItemMappingType;
use App\Domain\PriceIndices\Domain\Enums\CommodityCodeKind;
use App\Domain\PriceIndices\Infrastructure\Import\CommodityCodeParser;
use Illuminate\Support\Facades\DB;

final class GenerateClassifierItemMappings
{
    private const METHOD_EXACT = 'automatic:exact_code_and_name';

    private const METHOD_CODE_CONFLICT = 'automatic:exact_code_name_conflict';

    private const METHOD_CODE_ABSENT = 'automatic:canonical_code_absent';

    private const METHOD_LOCAL_ROSSTAT = 'automatic:rosstat_local_ag';

    private const METHOD_INVALID_CODE = 'automatic:unsupported_local_code';

    public function __construct(
        private readonly ResolveClassifierMappingContext $context,
        private readonly StatisticalNameNormalizer $names,
        private readonly CommodityCodeParser $codes,
    ) {}

    public function execute(string $classifierCode): ClassifierItemMappingGenerationResult
    {
        return DB::transaction(function () use ($classifierCode): ClassifierItemMappingGenerationResult {
            $context = $this->context->execute($classifierCode, lockActivePointer: true);
            $version = $context['version'];
            $counts = [
                'total' => 0,
                'exact' => 0,
                'ambiguous' => 0,
                'local_rosstat' => 0,
                'unmapped' => 0,
                'manual_preserved' => 0,
            ];

            $this->context->compatibleItemsQuery($context['aliases'])
                ->orderBy('statistical_classifier_items.id')
                ->chunkById(500, function ($items) use ($version, &$counts): void {
                    $nodes = StatisticalClassifierNode::query()
                        ->where('classifier_version_id', $version->id)
                        ->whereIn('code', $items->pluck('item_code')->all())
                        ->get()
                        ->keyBy('code');

                    foreach ($items as $item) {
                        $counts['total']++;
                        $mapping = StatisticalClassifierItemMapping::query()
                            ->where('statistical_classifier_item_id', $item->id)
                            ->where('classifier_version_id', $version->id)
                            ->first();

                        if ($mapping !== null && $mapping->isOperatorOwned()) {
                            $counts['manual_preserved']++;

                            continue;
                        }

                        $decision = $this->decision($item, $nodes->get($item->item_code));
                        $counts[$decision['count']]++;

                        $mapping ??= new StatisticalClassifierItemMapping([
                            'statistical_classifier_item_id' => $item->id,
                            'classifier_version_id' => $version->id,
                        ]);
                        if ($decision['attributes']['review_status'] === ClassifierItemMappingReviewStatus::Confirmed) {
                            $decision['attributes']['confirmed_at'] = $mapping->confirmed_at ?? now();
                        }
                        $mapping->fill($decision['attributes']);

                        if (! $mapping->exists || $mapping->isDirty()) {
                            $mapping->save();
                        }
                    }
                }, 'statistical_classifier_items.id', 'id');

            return new ClassifierItemMappingGenerationResult(
                $context['classifier']->code,
                $version->public_id,
                $version->version_label,
                $counts['total'],
                $counts['exact'],
                $counts['ambiguous'],
                $counts['local_rosstat'],
                $counts['unmapped'],
                $counts['manual_preserved'],
            );
        }, 3);
    }

    /**
     * @return array{count: string, attributes: array<string, mixed>}
     */
    private function decision(StatisticalClassifierItem $item, ?StatisticalClassifierNode $node): array
    {
        $parsed = $this->codes->parse($item->item_code);
        $baseEvidence = [
            'local_code' => $item->item_code,
            'local_normalized_name' => $this->names->normalize($item->name),
        ];

        if ($parsed?->codeKind === CommodityCodeKind::RosstatLocalAg) {
            return $this->automaticDecision(
                'local_rosstat',
                ClassifierItemMappingType::LocalRosstat,
                ClassifierItemMappingReviewStatus::Confirmed,
                self::METHOD_LOCAL_ROSSTAT,
                null,
                '1.0000',
                $baseEvidence + ['reason' => 'rosstat_local_ag_designation'],
            );
        }

        if ($parsed?->codeKind !== CommodityCodeKind::Numeric) {
            return $this->automaticDecision(
                'unmapped',
                ClassifierItemMappingType::Unmapped,
                ClassifierItemMappingReviewStatus::Proposed,
                self::METHOD_INVALID_CODE,
                null,
                null,
                $baseEvidence + ['reason' => 'unsupported_local_code'],
            );
        }

        if ($node === null) {
            return $this->automaticDecision(
                'unmapped',
                ClassifierItemMappingType::Unmapped,
                ClassifierItemMappingReviewStatus::Proposed,
                self::METHOD_CODE_ABSENT,
                null,
                null,
                $baseEvidence + ['reason' => 'canonical_code_absent'],
            );
        }

        $canonicalNormalizedName = $this->names->normalize($node->name);
        $evidence = $baseEvidence + [
            'canonical_code' => $node->code,
            'canonical_normalized_name' => $canonicalNormalizedName,
        ];

        if ($baseEvidence['local_normalized_name'] === $canonicalNormalizedName) {
            return $this->automaticDecision(
                'exact',
                ClassifierItemMappingType::Exact,
                ClassifierItemMappingReviewStatus::Confirmed,
                self::METHOD_EXACT,
                $node->id,
                '1.0000',
                $evidence + ['reason' => 'exact_normalized_code_and_name'],
            );
        }

        return $this->automaticDecision(
            'ambiguous',
            ClassifierItemMappingType::Ambiguous,
            ClassifierItemMappingReviewStatus::NeedsReview,
            self::METHOD_CODE_CONFLICT,
            $node->id,
            null,
            $evidence + ['reason' => 'exact_code_name_conflict'],
        );
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array{count: string, attributes: array<string, mixed>}
     */
    private function automaticDecision(
        string $count,
        ClassifierItemMappingType $mappingType,
        ClassifierItemMappingReviewStatus $reviewStatus,
        string $method,
        ?int $nodeId,
        ?string $confidence,
        array $evidence,
    ): array {
        return [
            'count' => $count,
            'attributes' => [
                'classifier_node_id' => $nodeId,
                'mapping_type' => $mappingType,
                'review_status' => $reviewStatus,
                'method' => $method,
                'confidence' => $confidence,
                'evidence_json' => $evidence,
                'confirmed_at' => null,
                'confirmed_by' => null,
            ],
        ];
    }
}
