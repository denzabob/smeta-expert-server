<?php

declare(strict_types=1);

namespace App\Services\Expert;

final readonly class ExpertWorkloadAssessment
{
    public function __construct(
        public int $materialCount,
        public int $pdfCount,
        public int $imageCount,
        public int $sourceBytes,
        public int $pageCount,
        public int $estimatedTextChars,
        public int $preparedPayloadBytes,
        public int $estimatedContextTokens,
        public string $coverageMode,
        public string $executionStrategy,
        public bool $directContextAllowed,
        public bool $requiresRetrievalPipeline,
        public string $reason,
    ) {}

    public function requiresMultiDocumentPipeline(): bool
    {
        return in_array($this->executionStrategy, [
            ExpertAnalysisExecutionStrategy::MULTI_DOCUMENT,
            ExpertAnalysisExecutionStrategy::MULTI_DOCUMENT_EXHAUSTIVE,
        ], true);
    }

    /** @return array<string, mixed> */
    public function toMetadata(): array
    {
        return [
            'material_count' => $this->materialCount,
            'pdf_count' => $this->pdfCount,
            'image_count' => $this->imageCount,
            'source_bytes' => $this->sourceBytes,
            'page_count' => $this->pageCount,
            'estimated_text_chars' => $this->estimatedTextChars,
            'prepared_payload_bytes' => $this->preparedPayloadBytes,
            'estimated_context_tokens' => $this->estimatedContextTokens,
            'coverage_mode' => $this->coverageMode,
            'execution_strategy' => $this->executionStrategy,
            'direct_context_allowed' => $this->directContextAllowed,
            'requires_retrieval_pipeline' => $this->requiresRetrievalPipeline,
            'strategy_reason' => $this->reason,
            'pipeline_stages' => ExpertAnalysisExecutionStrategy::requiresPipeline($this->executionStrategy)
                ? ExpertMultiDocumentPipelineStage::all()
                : [],
            'multi_document_execution' => ExpertAnalysisExecutionStrategy::requiresPipeline($this->executionStrategy)
                ? [
                    'cancellable' => true,
                    'resumable' => true,
                    'retry_failed_material' => true,
                ]
                : null,
        ];
    }
}
