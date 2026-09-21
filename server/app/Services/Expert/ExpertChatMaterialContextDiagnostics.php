<?php

declare(strict_types=1);

namespace App\Services\Expert;

use Illuminate\Support\Facades\Log;

final class ExpertChatMaterialContextDiagnostics
{
    /**
     * @param  list<string>  $currentIds
     * @param  list<string>  $historicalIds
     */
    public function log(
        string $runId,
        ExpertChatMaterialContextBundle $bundle,
        array $currentIds,
        array $historicalIds,
    ): void {
        $names = $this->materialNames($bundle);
        $textIds = $this->uniqueIds([
            ...array_column($bundle->current->textMaterials, 'public_id'),
            ...array_column($bundle->historical->textMaterials, 'public_id'),
        ]);
        $imageIds = $this->uniqueIds([
            ...array_map(static fn ($image): string => $image->materialPublicId, $bundle->current->images),
            ...array_map(static fn ($image): string => $image->materialPublicId, $bundle->historical->images),
        ]);
        $fileIds = $this->uniqueIds([
            ...array_map(static fn ($candidate): string => $candidate->materialPublicId, $bundle->current->ocrCandidates),
            ...array_map(static fn ($candidate): string => $candidate->materialPublicId, $bundle->historical->ocrCandidates),
        ]);

        Log::info('Expert chat material context resolved.', [
            'run_id' => $runId,
            'current_material_ids' => array_values($currentIds),
            'current_material_names' => $this->namesFor($currentIds, $names),
            'historical_resolved_ids' => array_values($historicalIds),
            'historical_resolved_names' => $this->namesFor($historicalIds, $names),
            'text_material_ids' => $textIds,
            'image_material_ids' => $imageIds,
            'file_material_ids' => $fileIds,
            'material_context_order' => $this->order($bundle),
        ]);
    }

    /** @return array<string, string> */
    private function materialNames(ExpertChatMaterialContextBundle $bundle): array
    {
        $names = [];
        foreach ([$bundle->current, $bundle->historical] as $context) {
            foreach ($context->textMaterials as $material) {
                $names[(string) $material['public_id']] = $this->safeName((string) $material['name']);
            }
            foreach ($context->images as $image) {
                $names[$image->materialPublicId] = $this->safeName($image->name);
            }
            foreach ($context->ocrCandidates as $candidate) {
                $names[$candidate->materialPublicId] = $this->safeName($candidate->name);
            }
        }

        return $names;
    }

    /** @param list<string> $ids @param array<string, string> $names @return list<string> */
    private function namesFor(array $ids, array $names): array
    {
        return array_values(array_map(
            static fn (string $id): string => $names[$id] ?? 'Материал без названия',
            $ids,
        ));
    }

    /** @param list<mixed> $ids @return list<string> */
    private function uniqueIds(array $ids): array
    {
        return array_values(array_unique(array_filter($ids, static fn (mixed $id): bool => is_string($id) && $id !== '')));
    }

    /** @return list<array{source: string, kind: string, material_id: string}> */
    private function order(ExpertChatMaterialContextBundle $bundle): array
    {
        $entries = [];
        foreach (['current' => $bundle->current, 'historical' => $bundle->historical] as $source => $context) {
            foreach ($context->textMaterials as $material) {
                $entries[] = ['source' => $source, 'kind' => 'text', 'material_id' => (string) $material['public_id']];
            }
            foreach ($context->images as $image) {
                $entries[] = ['source' => $source, 'kind' => 'image', 'material_id' => $image->materialPublicId];
            }
            foreach ($context->ocrCandidates as $candidate) {
                $entries[] = ['source' => $source, 'kind' => 'file', 'material_id' => $candidate->materialPublicId];
            }
        }

        return $entries;
    }

    private function safeName(string $value): string
    {
        $segments = preg_split('~[\\\\/]+~', $value) ?: [];
        $name = (string) ($segments[array_key_last($segments)] ?? '');
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name));

        return mb_substr($name === '' ? 'Материал без названия' : $name, 0, 180);
    }
}
