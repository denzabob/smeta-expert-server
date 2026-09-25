<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Models\Expert\ExpertConversation;
use Illuminate\Support\Facades\Log;

final class ExpertContextResolver
{
    public function __construct(
        private readonly ExpertContextDeterministicResolver $deterministic,
        private readonly ExpertSemanticContextResolver $semantic,
        private readonly ExpertContextResolutionValidator $validator,
    ) {}

    public function resolve(
        ExpertConversation $conversation,
        string $query,
        ExpertTaskIntent $intent,
        ExpertConversationMaterialState $state,
        ExpertContextCandidatePool $pool,
    ): ExpertContextResolution {
        $startedAt = microtime(true);
        $base = $this->logContext($pool, $intent);
        Log::info('Expert context resolution started', $base);
        $constraints = $this->deterministic->constraints($query, $intent, $state, $pool);
        if ($constraints->unresolvedExclusion) {
            return $this->ambiguous($pool, $intent, $constraints, [], $startedAt, false, 0);
        }
        $structural = $this->deterministic->resolve($query, $intent, $state, $pool, $constraints);

        if ($constraints->projectExhaustive) {
            return $this->finish($pool, $intent, $constraints, [], 'project', false, 'deterministic', 1.0, [], $startedAt, 0);
        }
        if ($structural['ambiguous_ids'] !== []) {
            return $this->ambiguous($pool, $intent, $constraints, $structural['ambiguous_ids'], $startedAt, false, 0);
        }
        if ($structural['complete']) {
            return $this->finish($pool, $intent, $constraints, $structural['selected'], $this->scope($structural['selected'], $intent), false, 'deterministic', 1.0, [], $startedAt, 0);
        }

        $needsSource = $this->needsSource($query, $intent, $state, $pool);
        if (! $needsSource && $structural['selected'] === []) {
            return $this->finish($pool, $intent, $constraints, [], 'single', false, 'deterministic', 1.0, [], $startedAt, 0);
        }
        if ($pool->projectSearchTruncated) {
            return $this->ambiguous($pool, $intent, $constraints, [], $startedAt, false, 0);
        }
        $semanticCandidates = $this->rankCandidates($query, $state, $pool, $constraints);
        $max = max(1, (int) config('expert.context.resolver.max_semantic_candidates', 40));
        if (count($constraints->hardIncludedIds) > $max || $semanticCandidates === []) {
            return $this->ambiguous($pool, $intent, $constraints, [], $startedAt, false, 0);
        }
        $semanticCandidates = array_slice($semanticCandidates, 0, $max);
        $semanticStartedAt = microtime(true);
        Log::info('Expert context semantic resolution requested', [
            ...$base, 'semantic_candidate_count' => count($semanticCandidates), 'semantic_used' => true,
        ]);

        try {
            $raw = $this->semantic->resolve($conversation, $query, $intent, $state, $semanticCandidates, $constraints);
            $validated = $this->validator->validate($raw, $semanticCandidates, $constraints, $conversation);
            $semanticMs = (int) round((microtime(true) - $semanticStartedAt) * 1000);
            Log::info('Expert context semantic resolution completed', [
                ...$base, 'semantic_candidate_count' => count($semanticCandidates),
                'semantic_resolution_ms' => $semanticMs,
                'selected_count' => count($validated['selected']),
                'ambiguous' => $validated['ambiguous'],
            ]);
            $tieIds = $this->unresolvedTieIds($query, $validated['selected'], $semanticCandidates, $intent);
            if ($validated['ambiguous'] || $validated['selected'] === []
                || $validated['confidence'] < (float) config('expert.context.resolver.min_confidence', 0.5)
                || $tieIds !== []) {
                $ids = $validated['ambiguous_ids'] !== []
                    ? $validated['ambiguous_ids']
                    : ($tieIds !== [] ? $tieIds : (array_column($validated['selected'], 'material_id') ?: array_map(
                        static fn (ExpertContextCandidate $candidate): string => $candidate->materialId,
                        array_slice($semanticCandidates, 0, 6),
                    )));

                return $this->ambiguous($pool, $intent, $constraints, $ids, $startedAt, true, $semanticMs);
            }

            return $this->finish(
                $pool, $intent, $constraints, $validated['selected'], $this->scope($validated['selected'], $intent),
                true, 'semantic', $validated['confidence'], [], $startedAt, $semanticMs,
            );
        } catch (\Throwable $exception) {
            $semanticMs = (int) round((microtime(true) - $semanticStartedAt) * 1000);
            Log::warning('Expert context resolution failed', [
                ...$base, 'semantic_candidate_count' => count($semanticCandidates),
                'semantic_resolution_ms' => $semanticMs, 'exception' => $exception::class,
            ]);

            return $this->ambiguous($pool, $intent, $constraints, array_map(
                static fn (ExpertContextCandidate $candidate): string => $candidate->materialId,
                array_slice($semanticCandidates, 0, 6),
            ), $startedAt, true, $semanticMs);
        }
    }

    /** @param list<ExpertContextCandidate> $candidates @param list<array<string, string>> $selected @return list<string> */
    private function unresolvedTieIds(string $query, array $selected, array $candidates, ExpertTaskIntent $intent): array
    {
        if (count($selected) !== 1 || $intent->crossDocument) {
            return [];
        }
        $scores = [];
        foreach ($candidates as $candidate) {
            $score = ExpertContextLexicalMatcher::score($candidate, $query);
            if ($score > 0) {
                $scores[$candidate->materialId] = $score;
            }
        }
        arsort($scores);
        $values = array_values($scores);

        if (count($values) <= 1 || $values[0] < 2 || $values[0] !== $values[1]
            || ($scores[$selected[0]['material_id']] ?? 0) !== $values[0]) {
            return [];
        }

        return array_keys(array_filter($scores, static fn (int $score): bool => $score === $values[0]));
    }

    /** @return list<ExpertContextCandidate> */
    private function rankCandidates(
        string $query,
        ExpertConversationMaterialState $state,
        ExpertContextCandidatePool $pool,
        ExpertContextConstraints $constraints,
    ): array {
        $recentRank = [];
        foreach ($state->recentSourceSets as $index => $set) {
            foreach ($set->materialIds as $id) {
                $recentRank[$id] ??= $index;
            }
        }
        $candidates = array_values(array_filter($pool->candidates, static fn (ExpertContextCandidate $candidate): bool => ! in_array($candidate->materialId, $constraints->hardExcludedIds, true)
            && (! $constraints->currentOnly || in_array('current', $candidate->origins, true))));
        $rank = static function (ExpertContextCandidate $candidate) use ($query, $constraints, $recentRank): array {
            $origins = $candidate->origins;

            return [
                in_array($candidate->materialId, $constraints->hardIncludedIds, true) ? 1 : 0,
                ExpertContextLexicalMatcher::exactAlias($candidate, $query) ? 1 : 0,
                ExpertContextLexicalMatcher::score($candidate, $query),
                in_array('current', $origins, true) ? 1 : 0,
                in_array('last_primary', $origins, true) || in_array('last_comparison', $origins, true) ? 1 : 0,
                in_array('active', $origins, true) ? 1 : 0,
                isset($recentRank[$candidate->materialId]) ? -$recentRank[$candidate->materialId] : -1000,
            ];
        };
        usort($candidates, static function (ExpertContextCandidate $a, ExpertContextCandidate $b) use ($rank): int {
            $left = $rank($a);
            $right = $rank($b);
            foreach ($left as $index => $value) {
                if ($value !== $right[$index]) {
                    return $right[$index] <=> $value;
                }
            }

            return strcmp($a->materialId, $b->materialId);
        });

        return $candidates;
    }

    private function needsSource(string $query, ExpertTaskIntent $intent, ExpertConversationMaterialState $state, ExpertContextCandidatePool $pool): bool
    {
        if ($pool->candidates === []) {
            return false;
        }
        if ($pool->currentBatch->orderedMaterialIds === []
            && preg_match('/\bбез\s+(?:файл\p{L}*|материал\p{L}*|документ\p{L}*)\b/iu', $query) === 1) {
            return false;
        }
        if ($pool->currentBatch->orderedMaterialIds !== []) {
            return true;
        }
        if ($intent->coverageMode === ExpertTaskIntent::EXHAUSTIVE || $intent->crossDocument) {
            return true;
        }
        foreach ($pool->candidates as $candidate) {
            if (ExpertContextLexicalMatcher::score($candidate, $query) > 0) {
                return true;
            }
        }
        if ($state->activeResearchSet !== [] && (str_contains($query, '?') || $intent->requiresReasoning)) {
            return true;
        }
        if ($intent->requiresNormatives || ! in_array($intent->taskType, [ExpertTaskIntent::QUESTION_ANSWERING, ExpertTaskIntent::GENERIC_ANALYSIS], true)) {
            return true;
        }

        return preg_match('/\b(?:документ\p{L}*|файл\p{L}*|материал\p{L}*|заключени\p{L}*|экспертиз\p{L}*|акт\p{L}*|определени\p{L}*|предыдущ\p{L}*|указан\p{L}*|упомянут\p{L}*)\b/iu', $query) === 1;
    }

    /** @param list<array<string, string>> $selected */
    private function scope(array $selected, ExpertTaskIntent $intent): string
    {
        if (count($selected) <= 1) {
            return 'single';
        }

        return $intent->coverageMode === ExpertTaskIntent::EXHAUSTIVE ? 'exhaustive_multi' : 'targeted_multi';
    }

    /** @param list<string> $ids */
    private function ambiguous(
        ExpertContextCandidatePool $pool,
        ExpertTaskIntent $intent,
        ExpertContextConstraints $constraints,
        array $ids,
        float $startedAt,
        bool $semanticUsed,
        int $semanticMs,
    ): ExpertContextResolution {
        $byId = [];
        foreach ($pool->candidates as $candidate) {
            $byId[$candidate->materialId] = $candidate;
        }
        if ($ids === []) {
            $ids = array_slice(array_keys($byId), 0, 6);
        }
        $candidates = [];
        foreach (array_slice(array_unique($ids), 0, 6) as $id) {
            if (isset($byId[$id]) && ! in_array($id, $constraints->hardExcludedIds, true)) {
                $candidates[] = ['material_public_id' => $id, 'name' => $byId[$id]->name];
            }
        }

        return $this->finish($pool, $intent, $constraints, [],
            $intent->coverageMode === ExpertTaskIntent::EXHAUSTIVE ? 'exhaustive_multi' : 'retrieval_multi', $semanticUsed,
            $semanticUsed ? 'semantic_ambiguous' : 'deterministic_ambiguous', 0.0, $candidates, $startedAt, $semanticMs);
    }

    /** @param list<array<string, string>> $selected @param list<array{material_public_id: string, name: string}> $ambiguousCandidates */
    private function finish(
        ExpertContextCandidatePool $pool,
        ExpertTaskIntent $intent,
        ExpertContextConstraints $constraints,
        array $selected,
        string $scope,
        bool $semanticUsed,
        string $source,
        float $confidence,
        array $ambiguousCandidates,
        float $startedAt,
        int $semanticMs,
    ): ExpertContextResolution {
        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
        $resolution = new ExpertContextResolution(
            $selected, $constraints->hardExcludedIds, $scope, $intent->coverageMode,
            str_contains($source, 'ambiguous'), $source, $confidence,
            (string) config('expert.context.resolver.version', 'v1'),
            hardIncludedIds: $constraints->hardIncludedIds,
            semanticUsed: $semanticUsed,
            ambiguousCandidates: $ambiguousCandidates,
            latencyMs: $latencyMs,
        );
        Log::info($resolution->ambiguous ? 'Expert context resolution ambiguous' : 'Expert context resolution completed', [
            ...$this->logContext($pool, $intent),
            'semantic_used' => $semanticUsed,
            'resolver_source' => $source,
            'resolver_version' => $resolution->resolverVersion,
            'resolver_latency_ms' => $latencyMs,
            'context_resolution_ms' => $latencyMs,
            'candidate_discovery_ms' => $pool->discoveryMs,
            'identity_lookup_ms' => $pool->identityLookupMs,
            'semantic_resolution_ms' => $semanticMs,
            'candidate_file_bytes_read' => 0,
            'selected_count' => count($selected),
            'selected_material_ids' => array_column($selected, 'material_id'),
            'selected_roles' => array_column($selected, 'role'),
            'ambiguous' => $resolution->ambiguous,
            'ambiguity_candidate_count' => count($ambiguousCandidates),
        ]);

        return $resolution;
    }

    /** @return array<string, mixed> */
    private function logContext(ExpertContextCandidatePool $pool, ExpertTaskIntent $intent): array
    {
        $count = static fn (string $origin): int => count(array_filter($pool->candidates,
            static fn (ExpertContextCandidate $item): bool => in_array($origin, $item->origins, true)));

        return [
            'message_id' => $pool->currentBatch->messageId,
            'task_type' => $intent->taskType,
            'coverage_mode' => $intent->coverageMode,
            'candidate_count' => count($pool->candidates),
            'current_candidate_count' => $count('current'),
            'active_candidate_count' => $count('active'),
            'recent_candidate_count' => $count('recent'),
            'project_candidate_count' => $count('project'),
        ];
    }
}
