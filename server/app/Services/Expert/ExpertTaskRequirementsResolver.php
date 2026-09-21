<?php

declare(strict_types=1);

namespace App\Services\Expert;

final class ExpertTaskRequirementsResolver
{
    public function resolve(ExpertContextPack $pack, string $message, ExpertChatMaterialContextBundle $bundle): ExpertTaskRequirements
    {
        $combined = $bundle->combined();
        $mimeTypes = [
            ...array_map(static fn (array $material): string => strtolower((string) ($material['mime_type'] ?? '')), $combined->textMaterials),
            ...array_map(static fn ($file): string => strtolower($file->mimeType), $combined->files),
            ...array_map(static fn ($image): string => strtolower($image->mimeType), $combined->images),
        ];
        $normalised = $this->normalise($message);
        $comparison = preg_match('/\b(сравни|сопоставь|между|противореч|различи|отличи|выводы двух|двух экспертиз)\b/u', $normalised) === 1;
        $reasoning = $comparison || preg_match('/\b(проанализируй|оцени обоснованность|оцени|подготовь вывод|проведи глубокий анализ|проверь методик|критическ|составь рецензию|выяви слабые места|аргументац)\b/u', $normalised) === 1;
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
            requiresVision: $combined->images !== [],
            requiresPdfProcessing: $combined->files !== [] || $combined->ocrCandidates !== [],
            requiresMultiDocumentPipeline: $pack->requiresMultiDocumentPipeline,
            requiresReasoning: $reasoning,
            requiresExhaustiveCoverage: $pack->coverageMode === 'exhaustive',
            hasExplicitComparison: $comparison,
        );
    }

    private function normalise(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower(str_replace('ё', 'е', $value), 'UTF-8')) ?? '');
    }
}
