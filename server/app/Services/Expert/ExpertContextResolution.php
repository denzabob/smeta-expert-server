<?php

declare(strict_types=1);

namespace App\Services\Expert;

final readonly class ExpertContextResolution
{
    /** @param list<array{material_id: string, role: string, origin: string, reason_code: string}> $selected @param list<string> $hardExcludedIds @param list<string> $hardIncludedIds */
    public function __construct(
        public array $selected,
        public array $hardExcludedIds,
        public string $scope,
        public string $coverageMode,
        public bool $ambiguous,
        public string $resolverSource,
        public float $confidence,
        public string $resolverVersion = '1',
        public string $projectCoreUsage = 'background',
        public array $hardIncludedIds = [],
        public bool $semanticUsed = false,
        public array $ambiguousCandidates = [],
        public int $latencyMs = 0,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'selected_sources' => $this->selected,
            'hard_included_ids' => $this->hardIncludedIds,
            'hard_excluded_ids' => $this->hardExcludedIds,
            'scope' => $this->scope,
            'coverage_mode' => $this->coverageMode,
            'ambiguity' => ['ambiguous' => $this->ambiguous],
            'resolver' => [
                'source' => $this->resolverSource,
                'version' => $this->resolverVersion,
                'confidence' => $this->confidence,
                'semantic_used' => $this->semanticUsed,
                'latency_ms' => $this->latencyMs,
            ],
            'project_core_usage' => $this->projectCoreUsage,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $sources = is_array($data['selected_sources'] ?? null) ? $data['selected_sources'] : [];
        $selected = array_values(array_filter($sources, static fn (mixed $item): bool => is_array($item)
            && is_string($item['material_id'] ?? null)
            && in_array($item['role'] ?? null, ['primary', 'comparison', 'supporting'], true)));
        $resolver = is_array($data['resolver'] ?? null) ? $data['resolver'] : [];
        $ambiguity = is_array($data['ambiguity'] ?? null) ? $data['ambiguity'] : [];
        $excluded = is_array($data['hard_excluded_ids'] ?? null) ? $data['hard_excluded_ids'] : [];
        $included = is_array($data['hard_included_ids'] ?? null) ? $data['hard_included_ids'] : [];

        return new self(
            $selected,
            array_values(array_filter($excluded, 'is_string')),
            (string) ($data['scope'] ?? 'single'),
            (string) ($data['coverage_mode'] ?? 'focused'),
            (bool) ($ambiguity['ambiguous'] ?? false),
            (string) ($resolver['source'] ?? 'unknown'),
            is_numeric($resolver['confidence'] ?? null) ? (float) $resolver['confidence'] : 0.0,
            (string) ($resolver['version'] ?? '1'),
            (string) ($data['project_core_usage'] ?? 'background'),
            array_values(array_filter($included, 'is_string')),
            (bool) ($resolver['semantic_used'] ?? false),
            [],
            is_numeric($resolver['latency_ms'] ?? null) ? (int) $resolver['latency_ms'] : 0,
        );
    }
}
