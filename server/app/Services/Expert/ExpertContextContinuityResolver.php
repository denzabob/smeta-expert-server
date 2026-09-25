<?php

declare(strict_types=1);

namespace App\Services\Expert;

/** Resolves discourse focus from source IDs and candidate descriptors, never document text or assistant replies. */
final class ExpertContextContinuityResolver
{
    /** @return array{continue_focus: bool, focused_ids: list<string>, shift_ids: list<string>, ambiguous_ids: list<string>, reason_code: string} */
    public function resolve(
        string $query,
        ExpertTaskIntent $intent,
        ExpertConversationMaterialState $state,
        ExpertContextCandidatePool $pool,
        ExpertContextConstraints $constraints,
    ): array {
        $none = ['continue_focus' => false, 'focused_ids' => [], 'shift_ids' => [], 'ambiguous_ids' => [], 'reason_code' => 'no_focus_continuation'];
        if (count($state->focusedPrimaryIds) !== 1
            || $pool->currentBatch->orderedMaterialIds !== []
            || $pool->explicitHistoricalIds !== []
            || $constraints->hardIncludedIds !== []
            || $constraints->hardExcludedIds !== []
            || $constraints->ambiguousExclusionIds !== []
            || $constraints->currentOnly
            || $constraints->projectExhaustive
            || $intent->crossDocument
            || $intent->coverageMode === ExpertTaskIntent::EXHAUSTIVE) {
            return $none;
        }

        $focusId = $state->focusedPrimaryIds[0];
        $focusAvailable = false;
        foreach ($pool->candidates as $candidate) {
            if ($candidate->materialId === $focusId && $candidate->availabilityStatus === 'uploaded') {
                $focusAvailable = true;
                break;
            }
        }
        if (! $focusAvailable) {
            return $none;
        }

        $referent = $this->newReferent($query) ?? $this->namedReferent($query, $focusId, $pool);
        if ($referent !== null) {
            $scores = [];
            foreach ($pool->candidates as $candidate) {
                if ($candidate->materialId === $focusId || $candidate->availabilityStatus !== 'uploaded') {
                    continue;
                }
                $score = ExpertContextLexicalMatcher::score($candidate, $referent);
                if ($score >= 2) {
                    $scores[$candidate->materialId] = $score;
                }
            }
            if ($scores !== []) {
                $best = max($scores);
                $ids = array_keys(array_filter($scores, static fn (int $score): bool => $score === $best));

                return [
                    'continue_focus' => false,
                    'focused_ids' => [],
                    'shift_ids' => count($ids) === 1 ? $ids : [],
                    'ambiguous_ids' => count($ids) > 1 ? $ids : [],
                    'reason_code' => 'focus_shift',
                ];
            }

            // An explicit new referent without a confident candidate must not revive old focus.
            return [...$none, 'reason_code' => 'focus_shift_unresolved'];
        }

        if (! $this->sourceDependent($query, $intent)) {
            return $none;
        }

        return ['continue_focus' => true, 'focused_ids' => [$focusId], 'shift_ids' => [], 'ambiguous_ids' => [], 'reason_code' => 'conversational_focus'];
    }

    private function newReferent(string $query): ?string
    {
        $normal = ExpertContextLexicalMatcher::normalize($query);
        if (preg_match('/\b(?:в|на|по|из|к|о|об|у)\s+((?:[\p{L}\p{N}.-]+\s*){1,3})/u', $normal, $matches) === 1) {
            $referent = trim($matches[1]);
            if (preg_match('/^(?:эт\p{L}*|том|нем|ней)\b/u', $referent) !== 1) {
                return $referent;
            }
        }
        if (preg_match('/\b(?:друг(?:ой|ом|ого|ую|ие|их)|ин(?:ой|ом|ого|ую)|вернемся|перейдем)\b/u', $normal) === 1) {
            return $normal;
        }
        if (preg_match_all('/\b\p{Lu}[\p{Ll}]{3,}\b/u', $query, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as [$word, $offset]) {
                if ($offset > 0) {
                    return $word;
                }
            }
        }

        return null;
    }

    private function namedReferent(string $query, string $focusId, ExpertContextCandidatePool $pool): ?string
    {
        foreach (ExpertContextLexicalMatcher::tokens($query) as $token) {
            $fragment = ExpertContextLexicalMatcher::discoveryFragment($token);
            foreach ($pool->candidates as $candidate) {
                if ($candidate->materialId === $focusId || $candidate->availabilityStatus !== 'uploaded') {
                    continue;
                }
                $aliases = $candidate->identity['aliases'] ?? [];
                foreach ([$candidate->name, ...(is_array($aliases) ? $aliases : [])] as $alias) {
                    if (! is_string($alias)) {
                        continue;
                    }
                    if (preg_match_all('/\b\p{Lu}[\p{Ll}]{3,}\b/u', $alias, $words, PREG_OFFSET_CAPTURE) < 1) {
                        continue;
                    }
                    foreach ($words[0] as [$word, $offset]) {
                        // The first title-cased word can be a document title; later ones are stronger identity cues.
                        if ($offset === 0) {
                            continue;
                        }
                        $nameToken = ExpertContextLexicalMatcher::normalize($word);
                        if (str_contains($nameToken, $fragment)) {
                            return $token;
                        }
                    }
                }
            }
        }

        return null;
    }

    private function sourceDependent(string $query, ExpertTaskIntent $intent): bool
    {
        if (in_array($intent->taskType, [ExpertTaskIntent::DRAFT], true)) {
            return false;
        }
        if (preg_match('/\b(?:предыдущ\p{L}*|прошл\p{L}*|ранее|вернемся|вернёмся|продолж\p{L}*)\b/iu', $query) === 1) {
            return true;
        }
        if (preg_match('/\b(?:какой|какая|какие|какое|кто|что|почему|сколько|когда|где|как|зачем)\b/iu', $query) === 1) {
            return true;
        }
        if (preg_match('/^\s*а\s+\S+/u', $query) === 1 && str_contains($query, '?')) {
            return true;
        }

        return in_array($intent->taskType, [ExpertTaskIntent::EXTRACT, ExpertTaskIntent::SUMMARIZE, ExpertTaskIntent::FIND, ExpertTaskIntent::CRITIQUE, ExpertTaskIntent::VERIFY, ExpertTaskIntent::ASSESS_COMPLIANCE, ExpertTaskIntent::DETERMINE_CAUSE], true);
    }
}
