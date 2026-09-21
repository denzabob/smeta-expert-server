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
            'requires_reasoning' => $this->requiresReasoning,
            'requires_exhaustive_coverage' => $this->requiresExhaustiveCoverage,
        ];
    }
}
