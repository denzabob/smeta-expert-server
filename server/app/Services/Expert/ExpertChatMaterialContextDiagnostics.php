<?php

declare(strict_types=1);

namespace App\Services\Expert;

use Illuminate\Support\Facades\Log;

final class ExpertChatMaterialContextDiagnostics
{
    public function logWorkload(string $runId, ExpertExecutionPlan $plan): void
    {
        $metadata = $plan->toMetadata();
        Log::info('Expert analysis workload assessed.', [
            'run_id' => $runId,
            'material_count' => $metadata['material_count'] ?? 0,
            'pdf_count' => $metadata['pdf_count'] ?? 0,
            'image_count' => $metadata['image_count'] ?? 0,
            'source_bytes' => $metadata['source_bytes'] ?? 0,
            'page_count' => $metadata['page_count'] ?? 0,
            'total_pages' => $metadata['page_count'] ?? 0,
            'estimated_text_chars' => $metadata['estimated_text_chars'] ?? 0,
            'prepared_payload_bytes' => $metadata['prepared_payload_bytes'] ?? 0,
            'estimated_context_tokens' => $metadata['estimated_context_tokens'] ?? 0,
            'scope' => $metadata['scope'] ?? null,
            'coverage_mode' => $metadata['coverage_mode'] ?? null,
            'execution_strategy' => $metadata['execution_strategy'] ?? null,
            'direct_context_allowed' => $metadata['direct_context_allowed'] ?? false,
            'strategy_reason' => $metadata['strategy_reason'] ?? null,
            'coverage_requested' => $metadata['coverage_requested'] ?? 0,
            'coverage_processed' => $metadata['coverage_processed'] ?? 0,
            'coverage_failed' => $metadata['coverage_failed'] ?? 0,
            'coverage_skipped' => $metadata['coverage_skipped'] ?? 0,
            'coverage_complete' => $metadata['coverage_complete'] ?? false,
            'requested_mode' => $metadata['requested_mode'] ?? null,
            'resolved_mode' => $metadata['resolved_mode'] ?? null,
            'task_type' => $metadata['task_type'] ?? null,
            'task_target' => $metadata['task_target'] ?? null,
            'material_scope' => $metadata['material_scope'] ?? null,
            'cross_document' => $metadata['cross_document'] ?? false,
            'domain' => $metadata['domain'] ?? null,
            'intent_confidence' => $metadata['intent_confidence'] ?? null,
            'intent_resolver_source' => $metadata['intent_resolver_source'] ?? null,
            'intent_signals' => $metadata['intent_signals'] ?? [],
        ]);
    }

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
        $plan = $bundle->plan;
        $textIds = $this->uniqueIds([
            ...array_column($bundle->current->textMaterials, 'public_id'),
            ...array_column($bundle->historical->textMaterials, 'public_id'),
            ...array_column($bundle->active?->textMaterials ?? [], 'public_id'),
        ]);
        $imageIds = $this->uniqueIds([
            ...array_map(static fn ($image): string => $image->materialPublicId, $bundle->current->images),
            ...array_map(static fn ($image): string => $image->materialPublicId, $bundle->historical->images),
            ...array_map(static fn ($image): string => $image->materialPublicId, $bundle->active?->images ?? []),
        ]);
        $fileIds = $this->uniqueIds([
            ...array_map(static fn ($candidate): string => $candidate->materialPublicId, $bundle->current->ocrCandidates),
            ...array_map(static fn ($candidate): string => $candidate->materialPublicId, $bundle->historical->ocrCandidates),
            ...array_map(static fn ($candidate): string => $candidate->materialPublicId, $bundle->active?->ocrCandidates ?? []),
        ]);

        Log::info('Expert chat material context resolved.', [
            'run_id' => $runId,
            'context_scope' => $plan?->scope,
            'coverage_mode' => $plan?->coverageMode,
            'active_material_count' => count($plan?->activeMaterials ?? []),
            'current_material_count' => count($currentIds),
            'historical_material_count' => count($historicalIds),
            'resolved_material_count' => count($plan?->resolvedMaterials ?? []),
            'requires_multi_document_pipeline' => $plan?->requiresMultiDocumentPipeline ?? false,
            'active_material_ids' => $plan?->activeMaterials ?? [],
            'resolved_material_ids' => $plan?->resolvedMaterials ?? [],
            'current_material_ids' => array_values($currentIds),
            'current_material_names' => $this->namesFor($currentIds, $names),
            'historical_resolved_ids' => array_values($historicalIds),
            'historical_resolved_names' => $this->namesFor($historicalIds, $names),
            'text_material_ids' => $textIds,
            'image_material_ids' => $imageIds,
            'file_material_ids' => $fileIds,
            'material_context_order' => $this->order($bundle),
        ]);

        foreach (['current' => $bundle->current, 'active' => $bundle->active, 'historical' => $bundle->historical] as $source => $context) {
            if ($context === null) {
                continue;
            }
            foreach ($context->textMaterials as $material) {
                if (($material['mime_type'] ?? null) !== 'application/pdf' || ! isset($material['processing_strategy'])) {
                    continue;
                }

                $this->logPdfProcessed(
                    $runId,
                    (string) $material['public_id'],
                    $source,
                    (string) $material['processing_strategy'],
                    (int) ($material['source_bytes'] ?? 0),
                    (int) ($material['extracted_chars'] ?? 0),
                    (int) ($material['page_count'] ?? 0),
                    (bool) ($material['cache_hit'] ?? false),
                );
            }
        }
    }

    public function logProviderPdf(
        string $runId,
        ExpertChatMaterialContextBundle $bundle,
        ExpertPdfOcrCandidate $candidate,
        int $extractedChars,
        bool $cacheHit = false,
    ): void {
        $source = 'current';
        foreach ($bundle->historical->ocrCandidates as $historicalCandidate) {
            if ($historicalCandidate->materialPublicId === $candidate->materialPublicId) {
                $source = 'historical';
                break;
            }
        }
        foreach ($bundle->active?->ocrCandidates ?? [] as $activeCandidate) {
            if ($activeCandidate->materialPublicId === $candidate->materialPublicId) {
                $source = 'active';
                break;
            }
        }

        $this->logPdfProcessed(
            $runId,
            $candidate->materialPublicId,
            $source,
            $candidate->processingStrategy(),
            strlen($candidate->bytes),
            $extractedChars,
            $candidate->pageCount,
            $cacheHit,
        );
    }

    /** @return array<string, string> */
    private function materialNames(ExpertChatMaterialContextBundle $bundle): array
    {
        $names = [];
        foreach ([$bundle->current, $bundle->active, $bundle->historical] as $context) {
            if ($context === null) {
                continue;
            }
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
        foreach (['current' => $bundle->current, 'active' => $bundle->active, 'historical' => $bundle->historical] as $source => $context) {
            if ($context === null) {
                continue;
            }
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

    private function logPdfProcessed(
        string $runId,
        string $materialId,
        string $source,
        string $processingStrategy,
        int $sourceBytes,
        int $extractedChars,
        int $pageCount,
        bool $cacheHit,
    ): void {
        Log::info('Expert chat PDF processed.', [
            'run_id' => $runId,
            'material_id' => $materialId,
            'source' => $source,
            'processing_strategy' => $processingStrategy,
            'source_bytes' => max(0, $sourceBytes),
            'extracted_chars' => max(0, $extractedChars),
            'page_count' => max(0, $pageCount),
            'cache_hit' => $cacheHit,
        ]);
    }
}
