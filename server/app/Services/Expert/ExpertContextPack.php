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
        public readonly ?ExpertAttachmentBatch $currentAttachmentBatch = null,
        public readonly ?ExpertContextCandidatePool $candidatePool = null,
        public readonly ?ExpertContextResolution $resolution = null,
    ) {}

    public function snapshot(?string $messageId = null): array
    {
        $batch = $this->currentAttachmentBatch ?? new ExpertAttachmentBatch(null, $this->currentMaterials);
        $resolution = $this->resolution ?? new ExpertContextResolution(
            array_map(static fn (string $id): array => [
                'material_id' => $id,
                'role' => 'primary',
                'origin' => 'legacy',
                'reason_code' => 'legacy_planner',
            ], $this->resolvedMaterials),
            [],
            $this->scope,
            $this->coverageMode,
            (bool) ($this->diagnostics['requires_material_disambiguation'] ?? false),
            'legacy_planner',
            0.0,
        );
        $candidateSet = $this->candidatePool?->metadata() ?? [
            'count' => count($this->resolvedMaterials),
            'hash' => hash('sha256', json_encode($this->resolvedMaterials, JSON_THROW_ON_ERROR)),
            'project_search_truncated' => false,
        ];
        $selectedIds = array_column($resolution->selected, 'material_id');

        return [
            'version' => 3,
            'project_core_version' => hash('sha256', $this->projectCore),
            'project_core' => $this->projectCore,
            'current_attachment_batch' => (new ExpertAttachmentBatch($messageId ?? $batch->messageId, $batch->orderedMaterialIds))->toArray(),
            'candidate_set' => $candidateSet,
            ...$resolution->toArray(),
            'current_material_ids' => array_values(array_intersect($this->currentMaterials, $selectedIds)),
            'active_material_ids' => $this->activeMaterials,
            'historical_material_ids' => array_values(array_intersect($this->historicalMaterials, $selectedIds)),
            'resolved_material_ids' => $selectedIds,
            'scope' => $resolution->scope,
            'coverage_mode' => $resolution->coverageMode,
            'requires_multi_document_pipeline' => $this->requiresMultiDocumentPipeline,
            'task_intent' => $this->intent?->toArray(),
        ];
    }

    public static function fromSnapshot(string $projectCore, array $snapshot): self
    {
        $version = (int) ($snapshot['version'] ?? 2);
        $resolution = $version >= 3 ? ExpertContextResolution::fromArray($snapshot) : null;
        $batch = is_array($snapshot['current_attachment_batch'] ?? null) ? $snapshot['current_attachment_batch'] : null;
        $selectedIds = $resolution === null ? null : array_column($resolution->selected, 'material_id');

        return new self(
            is_string($snapshot['project_core'] ?? null) ? $snapshot['project_core'] : $projectCore,
            $selectedIds === null ? ($snapshot['current_material_ids'] ?? []) : array_values(array_intersect($snapshot['current_material_ids'] ?? [], $selectedIds)),
            $snapshot['active_material_ids'] ?? [],
            $selectedIds === null ? ($snapshot['historical_material_ids'] ?? []) : array_values(array_intersect($snapshot['historical_material_ids'] ?? [], $selectedIds)),
            $selectedIds ?? ($snapshot['resolved_material_ids'] ?? []),
            $snapshot['scope'] ?? 'single',
            $snapshot['coverage_mode'] ?? 'focused',
            (bool) ($snapshot['requires_multi_document_pipeline'] ?? false),
            diagnostics: [
                'requires_material_disambiguation' => $resolution?->ambiguous ?? false,
                'candidate_count' => is_numeric($snapshot['candidate_set']['count'] ?? null) ? (int) $snapshot['candidate_set']['count'] : count($selectedIds ?? []),
            ],
            intent: is_array($snapshot['task_intent'] ?? null)
                ? ExpertTaskIntent::fromArray($snapshot['task_intent'])
                : null,
            currentAttachmentBatch: $batch === null ? null : new ExpertAttachmentBatch(
                is_string($batch['message_id'] ?? null) ? $batch['message_id'] : null,
                is_array($batch['ordered_material_ids'] ?? null) ? $batch['ordered_material_ids'] : [],
            ),
            resolution: $resolution,
        );
    }
}
