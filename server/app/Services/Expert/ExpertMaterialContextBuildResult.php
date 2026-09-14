<?php

namespace App\Services\Expert;

final readonly class ExpertMaterialContextBuildResult
{
    /**
     * @param list<array{public_id: string, name: string, mime_type: string, text: string}> $textMaterials
     * @param list<ExpertPdfOcrCandidate> $ocrCandidates
     */
    public function __construct(
        public array $textMaterials,
        public array $ocrCandidates,
    ) {}
}
