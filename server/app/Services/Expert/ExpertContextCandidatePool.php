<?php

declare(strict_types=1);

namespace App\Services\Expert;

final readonly class ExpertContextCandidatePool
{
    /** @param list<ExpertContextCandidate> $candidates */
    public function __construct(
        public array $candidates,
        public ExpertAttachmentBatch $currentBatch,
        public bool $projectSearchTruncated,
        public int $discoveryMs = 0,
        public int $identityLookupMs = 0,
    ) {}

    /** @return array{count: int, hash: string, project_search_truncated: bool} */
    public function metadata(): array
    {
        $descriptors = array_map(static fn (ExpertContextCandidate $candidate): array => $candidate->descriptor(), $this->candidates);
        usort($descriptors, static fn (array $a, array $b): int => strcmp($a['material_id'], $b['material_id']));

        return [
            'count' => count($descriptors),
            'hash' => hash('sha256', json_encode($descriptors, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'project_search_truncated' => $this->projectSearchTruncated,
        ];
    }
}
