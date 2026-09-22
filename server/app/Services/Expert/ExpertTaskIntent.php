<?php

declare(strict_types=1);

namespace App\Services\Expert;

final readonly class ExpertTaskIntent
{
    public const QUESTION_ANSWERING = 'question_answering';

    public const EXTRACT = 'extract';

    public const SUMMARIZE = 'summarize';

    public const COMPARE = 'compare';

    public const FIND = 'find';

    public const FIND_CONFLICTS = 'find_conflicts';

    public const CRITIQUE = 'critique';

    public const VERIFY = 'verify';

    public const ASSESS_COMPLIANCE = 'assess_compliance';

    public const DETERMINE_CAUSE = 'determine_cause';

    public const CALCULATE = 'calculate';

    public const DRAFT = 'draft';

    public const GENERIC_ANALYSIS = 'generic_analysis';

    public const CURRENT = 'current';

    public const SELECTED = 'selected';

    public const ACTIVE = 'active';

    public const PROJECT = 'project';

    public const EXPLICIT = 'explicit';

    public const AMBIGUOUS = 'ambiguous';

    public const FOCUSED = 'focused';

    public const EXHAUSTIVE = 'exhaustive';

    /** @var list<string> */
    public const TASK_TYPES = [
        self::QUESTION_ANSWERING,
        self::EXTRACT,
        self::SUMMARIZE,
        self::COMPARE,
        self::FIND,
        self::FIND_CONFLICTS,
        self::CRITIQUE,
        self::VERIFY,
        self::ASSESS_COMPLIANCE,
        self::DETERMINE_CAUSE,
        self::CALCULATE,
        self::DRAFT,
        self::GENERIC_ANALYSIS,
    ];

    /** @var list<string> */
    public const MATERIAL_SCOPES = [
        self::CURRENT,
        self::SELECTED,
        self::ACTIVE,
        self::PROJECT,
        self::EXPLICIT,
        self::AMBIGUOUS,
    ];

    /** @var list<string> */
    public const COVERAGE_MODES = [self::FOCUSED, self::EXHAUSTIVE];

    /**
     * @param  array<string, mixed>  $signals
     */
    public function __construct(
        public string $taskType,
        public ?string $target,
        public string $materialScope,
        public string $coverageMode,
        public bool $crossDocument,
        public bool $requiresReasoning,
        public bool $requiresNormatives,
        public bool $requiresCalculation,
        public bool $requiresVisualReading,
        public string $domain,
        public float $confidence,
        public string $resolverSource,
        public array $signals = [],
    ) {}

    /** @return list<string> */
    public function explicitMaterialIds(): array
    {
        $ids = $this->signals['explicit_material_ids'] ?? [];

        return array_values(array_filter(
            is_array($ids) ? $ids : [],
            static fn (mixed $id): bool => is_string($id) && $id !== '',
        ));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'task_type' => $this->taskType,
            'target' => $this->target,
            'material_scope' => $this->materialScope,
            'coverage_mode' => $this->coverageMode,
            'cross_document' => $this->crossDocument,
            'requires_reasoning' => $this->requiresReasoning,
            'requires_normatives' => $this->requiresNormatives,
            'requires_calculation' => $this->requiresCalculation,
            'requires_visual_reading' => $this->requiresVisualReading,
            'domain' => $this->domain,
            'confidence' => $this->confidence,
            'resolver_source' => $this->resolverSource,
            'signals' => $this->signals,
        ];
    }

    /** @return array<string, mixed> */
    public function toMetadata(): array
    {
        return [
            'task_type' => $this->taskType,
            'task_target' => $this->target,
            'material_scope' => $this->materialScope,
            'coverage_mode' => $this->coverageMode,
            'cross_document' => $this->crossDocument,
            'domain' => $this->domain,
            'intent_confidence' => $this->confidence,
            'intent_resolver_source' => $this->resolverSource,
            'intent_signals' => $this->signals,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            taskType: (string) ($data['task_type'] ?? $data['taskType'] ?? self::GENERIC_ANALYSIS),
            target: is_string($data['target'] ?? null) ? $data['target'] : null,
            materialScope: (string) ($data['material_scope'] ?? $data['materialScope'] ?? self::PROJECT),
            coverageMode: (string) ($data['coverage_mode'] ?? $data['coverageMode'] ?? self::FOCUSED),
            crossDocument: (bool) ($data['cross_document'] ?? $data['crossDocument'] ?? false),
            requiresReasoning: (bool) ($data['requires_reasoning'] ?? $data['requiresReasoning'] ?? false),
            requiresNormatives: (bool) ($data['requires_normatives'] ?? $data['requiresNormatives'] ?? false),
            requiresCalculation: (bool) ($data['requires_calculation'] ?? $data['requiresCalculation'] ?? false),
            requiresVisualReading: (bool) ($data['requires_visual_reading'] ?? $data['requiresVisualReading'] ?? false),
            domain: (string) ($data['domain'] ?? 'generic'),
            confidence: is_numeric($data['confidence'] ?? null) ? (float) $data['confidence'] : 0.0,
            resolverSource: (string) ($data['resolver_source'] ?? $data['resolverSource'] ?? 'legacy'),
            signals: is_array($data['signals'] ?? null) ? $data['signals'] : [],
        );
    }
}
