<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Models\Expert\ExpertProject;
use Illuminate\Support\Facades\Storage;

final class ExpertWorkloadAssessor
{
    public function __construct(
        private readonly ExpertMaterialProcessingLimits $limits,
    ) {}

    public function assessProject(ExpertProject $project, ExpertContextPack $pack): ExpertWorkloadAssessment
    {
        $materials = [];
        $disk = Storage::disk('local');
        $models = $project->materials()->whereIn('public_id', $pack->resolvedMaterials)->get()->keyBy('public_id');

        foreach ($pack->resolvedMaterials as $materialId) {
            $material = $models->get($materialId);
            if ($material === null || ! $disk->exists($material->storage_path)) {
                continue;
            }

            $extension = strtolower(ltrim(trim((string) $material->extension), '.'));
            $mimeType = strtolower(trim((string) $material->mime_type));
            $isPdf = $extension === 'pdf' || $mimeType === 'application/pdf';
            $bytes = max(0, (int) $disk->size($material->storage_path));
            $pageCount = 0;
            if ($isPdf) {
                $raw = $disk->get($material->storage_path);
                $count = preg_match_all('/\/Type\s*\/Page\b/', $raw);
                $pageCount = max(1, is_int($count) ? $count : 0);
            }

            $materials[] = [
                'public_id' => (string) $material->public_id,
                'name' => (string) $material->original_name,
                'mime_type' => (string) $material->mime_type,
                'text' => '',
                'source_bytes' => $bytes,
                'page_count' => $pageCount,
                'prepared_payload_bytes' => $isPdf
                    ? (int) (ceil($bytes / 3) * 4)
                    : $bytes,
            ];
        }

        $bundle = ExpertChatMaterialContextBundle::currentOnly(new ExpertChatMaterialContext($materials, []));

        return $this->assess($pack, null, $bundle);
    }

    public function assess(
        ExpertContextPack $pack,
        ?ExpertTaskRequirements $requirements,
        ExpertChatMaterialContextBundle $bundle,
    ): ExpertWorkloadAssessment {
        $materials = [];
        $pdfCount = 0;
        $imageCount = 0;
        $sourceBytes = 0;
        $pageCount = 0;
        $estimatedTextChars = 0;
        $preparedPayloadBytes = 0;

        foreach ($bundle->combined()->textMaterials as $material) {
            $this->mergeMaterial($materials, (string) ($material['public_id'] ?? ''), [
                'kind' => strtolower((string) ($material['mime_type'] ?? '')) === 'application/pdf'
                    ? 'pdf'
                    : (str_starts_with(strtolower((string) ($material['mime_type'] ?? '')), 'image/') ? 'image' : 'text'),
                'source_bytes' => max(0, (int) ($material['source_bytes'] ?? 0)),
                'page_count' => max(0, (int) ($material['page_count'] ?? 0)),
                'text_chars' => max(0, mb_strlen((string) ($material['text'] ?? ''), 'UTF-8')),
                'payload_bytes' => max(0, (int) ($material['prepared_payload_bytes'] ?? strlen((string) ($material['text'] ?? '')))),
            ]);
        }

        foreach ($bundle->combined()->ocrCandidates as $candidate) {
            $this->mergeMaterial($materials, $candidate->materialPublicId, [
                'kind' => 'pdf',
                'source_bytes' => strlen($candidate->bytes),
                'page_count' => max(0, $candidate->pageCount),
                'text_chars' => max(0, $candidate->extractedChars),
                'payload_bytes' => strlen(base64_encode($candidate->bytes)),
            ]);
        }

        foreach ($bundle->combined()->images as $image) {
            $this->mergeMaterial($materials, $image->materialPublicId, [
                'kind' => 'image',
                'source_bytes' => $image->sourceBytes > 0 ? $image->sourceBytes : strlen($image->bytes),
                'page_count' => 0,
                'text_chars' => 0,
                'payload_bytes' => strlen(base64_encode($image->bytes)),
            ]);
        }

        foreach ($materials as $material) {
            $sourceBytes += $material['source_bytes'];
            $pageCount += $material['page_count'];
            $estimatedTextChars += $material['text_chars'];
            $preparedPayloadBytes += $material['payload_bytes'];
            if ($material['kind'] === 'pdf') {
                $pdfCount++;
            } elseif ($material['kind'] === 'image') {
                $imageCount++;
            }
        }

        $direct = config('expert.analysis.direct', []);
        $maxMaterials = max(1, (int) ($direct['max_materials'] ?? 20));
        $maxPages = max(1, (int) ($direct['max_pages'] ?? 150));
        $maxSourceBytes = max(1, (int) ($direct['max_source_bytes'] ?? 50 * 1024 * 1024));
        $maxEstimatedTokens = max(1, (int) ($direct['max_estimated_tokens'] ?? 100000));
        // Binary files are provider payload, not extracted context tokens. The
        // two budgets must remain independent: a 15 MiB PDF does not imply
        // 15 MiB of prompt text.
        $estimatedContextTokens = (int) ceil($estimatedTextChars / 4);
        $pdfPayloadLimit = $this->limits->resolvePdf()['max_prepared_payload_bytes'];
        $directBudgetAllowed = count($pack->resolvedMaterials) <= $maxMaterials
            && ($pageCount === 0 || $pageCount <= $maxPages)
            && $sourceBytes <= $maxSourceBytes
            && ($pdfCount === 0 || $preparedPayloadBytes <= $pdfPayloadLimit)
            && $estimatedContextTokens <= $maxEstimatedTokens;

        $materialCount = count($pack->resolvedMaterials);
        $isExhaustive = $pack->coverageMode === 'exhaustive' && $materialCount > 1;
        $isRetrieval = $pack->scope === 'retrieval_multi' && $materialCount > 1;
        $directContextAllowed = $directBudgetAllowed && ! $isExhaustive && ! $isRetrieval;
        if ($isExhaustive) {
            $strategy = ExpertAnalysisExecutionStrategy::MULTI_DOCUMENT_EXHAUSTIVE;
            $reason = 'exhaustive_large_material_set';
        } elseif ($isRetrieval) {
            $strategy = ExpertAnalysisExecutionStrategy::RETRIEVAL;
            $reason = 'retrieval_across_material_set';
        } elseif ($directContextAllowed) {
            $strategy = ExpertAnalysisExecutionStrategy::DIRECT;
            $reason = 'direct_context_within_budget';
        } else {
            $strategy = ExpertAnalysisExecutionStrategy::MULTI_DOCUMENT;
            $reason = 'direct_context_budget_exceeded';
        }

        return new ExpertWorkloadAssessment(
            materialCount: $materialCount,
            pdfCount: $pdfCount,
            imageCount: $imageCount,
            sourceBytes: $sourceBytes,
            pageCount: $pageCount,
            estimatedTextChars: $estimatedTextChars,
            preparedPayloadBytes: $preparedPayloadBytes,
            estimatedContextTokens: $estimatedContextTokens,
            coverageMode: $pack->coverageMode,
            executionStrategy: $strategy,
            directContextAllowed: $directContextAllowed,
            requiresRetrievalPipeline: $strategy === ExpertAnalysisExecutionStrategy::RETRIEVAL,
            reason: $reason,
        );
    }

    /** @param array<string, array{kind: string, source_bytes: int, page_count: int, text_chars: int, payload_bytes: int}> $materials @param array{kind: string, source_bytes: int, page_count: int, text_chars: int, payload_bytes: int} $incoming */
    private function mergeMaterial(array &$materials, string $materialId, array $incoming): void
    {
        if ($materialId === '') {
            return;
        }

        $materials[$materialId] ??= [
            'kind' => $incoming['kind'],
            'source_bytes' => 0,
            'page_count' => 0,
            'text_chars' => 0,
            'payload_bytes' => 0,
        ];
        $materials[$materialId]['kind'] = $incoming['kind'] === 'pdf' || $materials[$materialId]['kind'] === 'pdf'
            ? 'pdf'
            : ($incoming['kind'] === 'image' || $materials[$materialId]['kind'] === 'image' ? 'image' : 'text');
        $materials[$materialId]['source_bytes'] = max($materials[$materialId]['source_bytes'], $incoming['source_bytes']);
        $materials[$materialId]['page_count'] = max($materials[$materialId]['page_count'], $incoming['page_count']);
        $materials[$materialId]['text_chars'] = max($materials[$materialId]['text_chars'], $incoming['text_chars']);
        $materials[$materialId]['payload_bytes'] = max($materials[$materialId]['payload_bytes'], $incoming['payload_bytes']);
    }
}
