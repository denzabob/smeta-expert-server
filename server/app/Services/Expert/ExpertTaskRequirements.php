<?php

declare(strict_types=1);

namespace App\Services\Expert;

final readonly class ExpertTaskRequirements
{
    public function __construct(
        public int $materialCount,
        public int $currentMaterialCount,
        public int $activeMaterialCount,
        public bool $hasImages,
        public bool $hasPdf,
        public bool $hasScannedPdf,
        public bool $hasSpreadsheet,
        public string $scope,
        public string $coverageMode,
        public bool $requiresVision,
        public bool $requiresPdfProcessing,
        public bool $requiresMultiDocumentPipeline,
        public bool $requiresReasoning,
        public bool $requiresExhaustiveCoverage,
        public bool $hasExplicitComparison,
        public bool $requiresRetrievalPipeline = false,
        public ?ExpertWorkloadAssessment $workload = null,
        public ?ExpertAnalysisCoverage $coverage = null,
        public ?ExpertTaskIntent $intent = null,
    ) {}

    /** @return array<string, mixed> */
    public function toMetadata(): array
    {
        return [
            'material_count' => $this->materialCount,
            'current_material_count' => $this->currentMaterialCount,
            'active_material_count' => $this->activeMaterialCount,
            'has_images' => $this->hasImages,
            'has_pdf' => $this->hasPdf,
            'has_scanned_pdf' => $this->hasScannedPdf,
            'has_spreadsheet' => $this->hasSpreadsheet,
            'scope' => $this->scope,
            'coverage_mode' => $this->coverageMode,
            'requires_vision' => $this->requiresVision,
            'requires_pdf_processing' => $this->requiresPdfProcessing,
            'requires_multi_document_pipeline' => $this->requiresMultiDocumentPipeline,
            'requires_retrieval_pipeline' => $this->requiresRetrievalPipeline,
            'requires_reasoning' => $this->requiresReasoning,
            'requires_exhaustive_coverage' => $this->requiresExhaustiveCoverage,
            ...($this->intent?->toMetadata() ?? []),
            ...($this->coverage?->toMetadata() ?? []),
            ...($this->workload?->toMetadata() ?? []),
        ];
    }

    public function withWorkload(ExpertWorkloadAssessment $workload): self
    {
        return new self(
            materialCount: $this->materialCount,
            currentMaterialCount: $this->currentMaterialCount,
            activeMaterialCount: $this->activeMaterialCount,
            hasImages: $this->hasImages,
            hasPdf: $this->hasPdf,
            hasScannedPdf: $this->hasScannedPdf,
            hasSpreadsheet: $this->hasSpreadsheet,
            scope: $this->scope,
            coverageMode: $this->coverageMode,
            requiresVision: $this->requiresVision,
            requiresPdfProcessing: $this->requiresPdfProcessing,
            requiresMultiDocumentPipeline: $workload->requiresMultiDocumentPipeline(),
            requiresReasoning: $this->requiresReasoning,
            requiresExhaustiveCoverage: $this->requiresExhaustiveCoverage,
            hasExplicitComparison: $this->hasExplicitComparison,
            requiresRetrievalPipeline: $workload->requiresRetrievalPipeline,
            workload: $workload,
            coverage: $this->coverage,
            intent: $this->intent,
        );
    }

    public function withCoverage(ExpertAnalysisCoverage $coverage): self
    {
        return new self(
            materialCount: $this->materialCount,
            currentMaterialCount: $this->currentMaterialCount,
            activeMaterialCount: $this->activeMaterialCount,
            hasImages: $this->hasImages,
            hasPdf: $this->hasPdf,
            hasScannedPdf: $this->hasScannedPdf,
            hasSpreadsheet: $this->hasSpreadsheet,
            scope: $this->scope,
            coverageMode: $this->coverageMode,
            requiresVision: $this->requiresVision,
            requiresPdfProcessing: $this->requiresPdfProcessing,
            requiresMultiDocumentPipeline: $this->requiresMultiDocumentPipeline,
            requiresReasoning: $this->requiresReasoning,
            requiresExhaustiveCoverage: $this->requiresExhaustiveCoverage,
            hasExplicitComparison: $this->hasExplicitComparison,
            requiresRetrievalPipeline: $this->requiresRetrievalPipeline,
            workload: $this->workload,
            coverage: $coverage,
            intent: $this->intent,
        );
    }
}
