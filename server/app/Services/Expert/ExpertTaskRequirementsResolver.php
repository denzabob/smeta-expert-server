<?php

declare(strict_types=1);

namespace App\Services\Expert;

final class ExpertTaskRequirementsResolver
{
    public function resolve(
        ExpertContextPack $pack,
        string $message,
        ExpertChatMaterialContextBundle $bundle,
        ?ExpertTaskIntent $intent = null,
    ): ExpertTaskRequirements {
        $combined = $bundle->combined();
        $mimeTypes = [
            ...array_map(static fn (array $material): string => strtolower((string) ($material['mime_type'] ?? '')), $combined->textMaterials),
            ...array_map(static fn ($file): string => strtolower($file->mimeType), $combined->files),
            ...array_map(static fn ($image): string => strtolower($image->mimeType), $combined->images),
        ];
        $intent ??= $pack->intent;
        $legacyNormalised = $intent === null ? $this->normalise($message) : null;
        $comparison = $intent?->taskType === ExpertTaskIntent::COMPARE
            || ($legacyNormalised !== null && preg_match('/\b(сравни|сопоставь|между|противореч|различи|отличи|выводы двух|двух экспертиз)\b/u', $legacyNormalised) === 1);
        $reasoning = $intent?->requiresReasoning
            || ($legacyNormalised !== null && ($comparison || preg_match('/\b(проанализируй|оцени обоснованность|оцени|подготовь вывод|проведи глубокий анализ|проверь методик|критическ|составь рецензию|выяви слабые места|аргументац)\b/u', $legacyNormalised) === 1));
        $hasPdf = in_array('application/pdf', $mimeTypes, true) || $combined->ocrCandidates !== [];
        $hasScannedPdf = false;
        foreach ($combined->ocrCandidates as $candidate) {
            if ($candidate->processingIntent->value === 'pdf_ocr') {
                $hasScannedPdf = true;
                break;
            }
        }
        $hasSpreadsheet = in_array('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $mimeTypes, true)
            || in_array('application/vnd.ms-excel', $mimeTypes, true)
            || (bool) preg_match('/\.(xlsx?|ods)$/iu', implode(' ', array_map(static fn (array $material): string => (string) ($material['name'] ?? ''), $combined->textMaterials)));

        return new ExpertTaskRequirements(
            materialCount: count($pack->resolvedMaterials),
            currentMaterialCount: count($pack->currentMaterials),
            activeMaterialCount: count($pack->activeMaterials),
            hasImages: $combined->images !== [],
            hasPdf: $hasPdf,
            hasScannedPdf: $hasScannedPdf,
            hasSpreadsheet: $hasSpreadsheet,
            scope: $pack->scope,
            coverageMode: $pack->coverageMode,
            requiresVision: $combined->images !== [] || ($intent?->requiresVisualReading ?? false),
            requiresPdfProcessing: $combined->files !== [] || $combined->ocrCandidates !== [],
            requiresMultiDocumentPipeline: $pack->requiresMultiDocumentPipeline,
            requiresReasoning: (bool) $reasoning,
            requiresExhaustiveCoverage: $intent?->coverageMode === ExpertTaskIntent::EXHAUSTIVE || $pack->coverageMode === 'exhaustive',
            hasExplicitComparison: $comparison,
            intent: $intent,
        );
    }

    private function normalise(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower(str_replace('ё', 'е', $value), 'UTF-8')) ?? '');
    }
}
