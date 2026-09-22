<?php

declare(strict_types=1);

namespace App\Services\Expert;

final class ExpertContextPack
{
    /** @param list<string> $currentMaterials @param list<string> $activeMaterials @param list<string> $historicalMaterials @param list<string> $resolvedMaterials */
    public function __construct(
        public readonly string $projectCore,
        public readonly array $currentMaterials,
        public readonly array $activeMaterials,
        public readonly array $historicalMaterials,
        public readonly array $resolvedMaterials,
        public readonly string $scope,
        public readonly string $coverageMode,
        public readonly bool $requiresMultiDocumentPipeline,
        public readonly array $diagnostics = [],
        public readonly array $chatHistory = [],
        public readonly ?ExpertTaskIntent $intent = null,
    ) {}

    public function snapshot(): array
    {
        return [
            'version' => 2,
            'project_core_version' => hash('sha256', $this->projectCore),
            'project_core' => $this->projectCore,
            'current_material_ids' => $this->currentMaterials,
            'active_material_ids' => $this->activeMaterials,
            'historical_material_ids' => $this->historicalMaterials,
            'resolved_material_ids' => $this->resolvedMaterials,
            'scope' => $this->scope,
            'coverage_mode' => $this->coverageMode,
            'requires_multi_document_pipeline' => $this->requiresMultiDocumentPipeline,
            'task_intent' => $this->intent?->toArray(),
        ];
    }

    public static function fromSnapshot(string $projectCore, array $snapshot): self
    {
        return new self(
            is_string($snapshot['project_core'] ?? null) ? $snapshot['project_core'] : $projectCore,
            $snapshot['current_material_ids'] ?? [],
            $snapshot['active_material_ids'] ?? [],
            $snapshot['historical_material_ids'] ?? [],
            $snapshot['resolved_material_ids'] ?? [],
            $snapshot['scope'] ?? 'single',
            $snapshot['coverage_mode'] ?? 'focused',
            (bool) ($snapshot['requires_multi_document_pipeline'] ?? false),
            intent: is_array($snapshot['task_intent'] ?? null)
                ? ExpertTaskIntent::fromArray($snapshot['task_intent'])
                : null,
        );
    }
}
