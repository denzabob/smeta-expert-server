<?php

declare(strict_types=1);

namespace App\Services\Expert;

final readonly class ExpertContextConstraints
{
    /** @param list<string> $hardIncludedIds @param list<string> $hardExcludedIds @param list<string> $explicitCandidateIds @param list<string> $ambiguousExclusionIds */
    public function __construct(
        public array $hardIncludedIds,
        public array $hardExcludedIds,
        public bool $currentOnly,
        public bool $projectExhaustive,
        public array $explicitCandidateIds,
        public ?int $ordinalPosition = null,
        public array $ambiguousExclusionIds = [],
        public bool $unresolvedExclusion = false,
    ) {}
}
