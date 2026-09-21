<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Services\LLM\DTO\LLMFileContent;
use App\Services\LLM\DTO\LLMImageContent;

final class ExpertChatMaterialContextBundle
{
    public function __construct(
        public readonly ExpertChatMaterialContext $current,
        public readonly ExpertChatMaterialContext $historical,
        public readonly ?ExpertChatMaterialContext $active = null,
        public readonly ?ExpertContextPack $plan = null,
    ) {}

    public static function currentOnly(ExpertChatMaterialContext $context): self
    {
        return new self($context, new ExpertChatMaterialContext([], []));
    }

    /**
     * @param  list<string>  $currentIds
     * @param  list<string>  $historicalIds
     */
    public static function partition(
        ExpertChatMaterialContext $combined,
        array $currentIds,
        array $historicalIds,
        array $activeIds = [],
        ?ExpertContextPack $plan = null,
    ): self {
        $current = array_fill_keys($currentIds, true);
        $historical = array_fill_keys(array_values(array_diff($historicalIds, $currentIds)), true);
        $active = array_fill_keys(array_values(array_diff($activeIds, $currentIds, $historicalIds)), true);
        $fileMaterialIds = [];
        foreach ($combined->ocrCandidates as $candidate) {
            $fileMaterialIds[strtolower($candidate->sha256)] = $candidate->materialPublicId;
        }

        $select = static function (array $ids) use ($combined, $fileMaterialIds): ExpertChatMaterialContext {
            $textMaterials = array_values(array_filter(
                $combined->textMaterials,
                static fn (array $material): bool => isset($ids[(string) $material['public_id']]),
            ));
            $images = array_values(array_filter(
                $combined->images,
                static fn (LLMImageContent $image): bool => isset($ids[$image->materialPublicId]),
            ));
            $files = array_values(array_filter(
                $combined->files,
                static fn (LLMFileContent $file): bool => isset($ids[$fileMaterialIds[strtolower($file->sha256)] ?? '']),
            ));
            $ocrCandidates = array_values(array_filter(
                $combined->ocrCandidates,
                static fn (ExpertPdfOcrCandidate $candidate): bool => isset($ids[$candidate->materialPublicId]),
            ));

            return new ExpertChatMaterialContext($textMaterials, $images, $files, $ocrCandidates);
        };

        return new self($select($current), $select($historical), $select($active), $plan);
    }

    public function combined(): ExpertChatMaterialContext
    {
        return new ExpertChatMaterialContext(
            [...$this->current->textMaterials, ...($this->active?->textMaterials ?? []), ...$this->historical->textMaterials],
            [...$this->current->images, ...($this->active?->images ?? []), ...$this->historical->images],
            [...$this->current->files, ...($this->active?->files ?? []), ...$this->historical->files],
            [...$this->current->ocrCandidates, ...($this->active?->ocrCandidates ?? []), ...$this->historical->ocrCandidates],
        );
    }

    /** @return list<array{public_id: string, name: string, mime_type: string, text: string, context_role: string}> */
    public function llmTextMaterials(): array
    {
        return [
            ...array_map(static fn (array $material): array => [...$material, 'context_role' => 'current', 'source_type' => 'CURRENT_MATERIAL', 'material_id' => $material['public_id'], 'filename' => $material['name']], $this->current->textMaterials),
            ...array_map(static fn (array $material): array => [...$material, 'context_role' => 'active', 'source_type' => 'ACTIVE_MATERIAL', 'material_id' => $material['public_id'], 'filename' => $material['name']], $this->active?->textMaterials ?? []),
            ...array_map(static fn (array $material): array => [...$material, 'context_role' => 'historical', 'source_type' => 'HISTORICAL_MATERIAL', 'material_id' => $material['public_id'], 'filename' => $material['name']], $this->historical->textMaterials),
        ];
    }
}
