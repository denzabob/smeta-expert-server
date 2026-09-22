<?php

declare(strict_types=1);

namespace App\Services\Expert;

/** Queue-ready contract; execution remains intentionally out of scope here. */
final readonly class ExpertMultiDocumentExecutionPlan
{
    /** @param list<string> $materialIds @param list<string> $stages */
    public function __construct(
        public array $materialIds,
        public array $stages = [
            ExpertMultiDocumentPipelineStage::PREPARE,
            ExpertMultiDocumentPipelineStage::MAP,
            ExpertMultiDocumentPipelineStage::COMPARE,
            ExpertMultiDocumentPipelineStage::REDUCE,
        ],
        public bool $cancellable = true,
        public bool $resumable = true,
        public bool $retryFailedMaterial = true,
    ) {}

    public static function fromContextPack(ExpertContextPack $pack): self
    {
        return new self($pack->resolvedMaterials);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'material_ids' => $this->materialIds,
            'stages' => $this->stages,
            'cancellable' => $this->cancellable,
            'resumable' => $this->resumable,
            'retry_failed_material' => $this->retryFailedMaterial,
        ];
    }
}
