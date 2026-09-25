<?php

declare(strict_types=1);

namespace App\Services\Expert;

final class ExpertContextDeterministicResolver
{
    public function constraints(
        string $query,
        ExpertTaskIntent $intent,
        ExpertConversationMaterialState $state,
        ExpertContextCandidatePool $pool,
    ): ExpertContextConstraints {
        $normal = ExpertContextLexicalMatcher::normalize($query);
        $current = $pool->currentBatch->orderedMaterialIds;
        $known = array_fill_keys(array_map(static fn (ExpertContextCandidate $item): string => $item->materialId, $pool->candidates), true);
        $explicit = array_values(array_filter($intent->explicitMaterialIds(), static fn (string $id): bool => isset($known[$id])));
        $currentOnly = preg_match('/\bтолько\b.{0,50}\b(?:приложенн\p{L}*|прикрепленн\p{L}*|загруженн\p{L}*|текущ\p{L}*)\b/u', $normal) === 1;
        $projectExhaustive = $intent->coverageMode === ExpertTaskIntent::EXHAUSTIVE
            && preg_match('/\b(?:всех|все|всем|каждом)\b.{0,40}\b(?:материал\p{L}*|документ\p{L}*|файл\p{L}*)\b.{0,30}\b(?:проект\p{L}*|библиотек\p{L}*)\b/u', $normal) === 1;

        $ordinal = null;
        if (! str_contains($normal, 'проекта') && preg_match('/\b(?:перв\p{L}*|втор\p{L}*|трет\p{L}*|последн\p{L}*)\b.{0,30}\b(?:документ\p{L}*|файл\p{L}*|изображени\p{L}*|материал\p{L}*)\b/u', $normal, $matches) === 1) {
            $word = $matches[0];
            $ordinal = str_contains($word, 'перв') ? 0 : (str_contains($word, 'втор') ? 1 : (str_contains($word, 'трет') ? 2 : -1));
        }
        $ordered = $current !== [] ? $current : ($state->lastCurrentBatch?->orderedMaterialIds ?? []);
        $ordinalId = $ordinal === null || $ordered === [] ? null : ($ordinal === -1 ? end($ordered) : ($ordered[$ordinal] ?? null));
        $currentReferenced = $current !== [] && preg_match('/\b(?:приложенн\p{L}*|прикрепленн\p{L}*|загруженн\p{L}*|текущ\p{L}*)\b/u', $normal) === 1;
        $hardIncluded = $explicit;
        if (is_string($ordinalId) && isset($known[$ordinalId])) {
            $hardIncluded[] = $ordinalId;
        } elseif ($currentReferenced && $ordinal === null) {
            $hardIncluded = [...$hardIncluded, ...$current];
        }

        $excluded = [];
        $ambiguousExclusions = [];
        $unresolvedExclusion = false;
        if (preg_match('/\bкроме\s+([^,;!?]+)/u', $normal, $matches) === 1
            && preg_match('/^того\b/u', trim($matches[1])) !== 1) {
            $target = trim($matches[1]);
            $matchesByName = array_values(array_filter($pool->candidates, static fn (ExpertContextCandidate $candidate): bool => ExpertContextLexicalMatcher::exactAlias($candidate, $target)
                || ExpertContextLexicalMatcher::score($candidate, $target) > 0));
            if (count($matchesByName) === 1) {
                $excluded[] = $matchesByName[0]->materialId;
            } elseif (count($matchesByName) > 1) {
                $ambiguousExclusions = array_map(static fn (ExpertContextCandidate $candidate): string => $candidate->materialId, $matchesByName);
            } else {
                $unresolvedExclusion = true;
            }
        }
        $ambiguousExclusions = array_values(array_unique([
            ...$ambiguousExclusions,
            ...array_intersect($hardIncluded, $excluded),
        ]));

        return new ExpertContextConstraints(
            array_values(array_unique($hardIncluded)),
            array_values(array_unique($excluded)),
            $currentOnly,
            $projectExhaustive,
            $explicit,
            $ordinal,
            $ambiguousExclusions,
            $unresolvedExclusion,
        );
    }

    /** @return array{selected: list<array{material_id: string, role: string, origin: string, reason_code: string}>, complete: bool, ambiguous_ids: list<string>} */
    public function resolve(
        string $query,
        ExpertTaskIntent $intent,
        ExpertConversationMaterialState $state,
        ExpertContextCandidatePool $pool,
        ExpertContextConstraints $constraints,
    ): array {
        $byId = [];
        foreach ($pool->candidates as $candidate) {
            $byId[$candidate->materialId] = $candidate;
        }
        if ($constraints->ambiguousExclusionIds !== []) {
            return ['selected' => [], 'complete' => false, 'ambiguous_ids' => $constraints->ambiguousExclusionIds];
        }
        if ($constraints->projectExhaustive) {
            return ['selected' => [], 'complete' => true, 'ambiguous_ids' => []];
        }

        $current = array_values(array_filter($pool->currentBatch->orderedMaterialIds, static fn (string $id): bool => isset($byId[$id]) && ! in_array($id, $constraints->hardExcludedIds, true)));
        $selected = [];
        foreach ($constraints->hardIncludedIds as $id) {
            if (isset($byId[$id]) && ! in_array($id, $constraints->hardExcludedIds, true)) {
                $selected[$id] = $this->source($byId[$id], 'primary', in_array($id, $current, true) ? 'current_batch' : 'explicit_user_selection');
            }
        }
        if ($constraints->currentOnly) {
            foreach ($current as $id) {
                $selected[$id] ??= $this->source($byId[$id], 'primary', 'current_batch');
            }

            return ['selected' => array_values($selected), 'complete' => $selected !== [], 'ambiguous_ids' => []];
        }

        $exact = [];
        foreach ($pool->candidates as $candidate) {
            if (in_array($candidate->materialId, $constraints->hardExcludedIds, true)) {
                continue;
            }
            $aliases = $candidate->identity['aliases'] ?? [];
            foreach (array_unique([$candidate->name, ...(is_array($aliases) ? $aliases : [])]) as $alias) {
                if (is_string($alias) && mb_strlen(ExpertContextLexicalMatcher::normalize($alias), 'UTF-8') >= 4
                    && (str_contains($alias, '.') || str_contains(ExpertContextLexicalMatcher::normalize($alias), ' '))
                    && ExpertContextLexicalMatcher::containsPhrase($query, $alias)) {
                    $exact[ExpertContextLexicalMatcher::normalize($alias)][$candidate->materialId] = $candidate;
                }
            }
        }
        foreach ($exact as $matching) {
            if (count($matching) > 1) {
                return ['selected' => array_values($selected), 'complete' => false, 'ambiguous_ids' => array_keys($matching)];
            }
            $candidate = reset($matching);
            $selected[$candidate->materialId] ??= $this->source($candidate, $selected === [] ? 'primary' : 'comparison', 'exact_alias_match');
        }
        if ($exact !== []) {
            if ($current !== [] && preg_match('/\b(?:общего|сравн\p{L}*|сопостав\p{L}*)\b/u', ExpertContextLexicalMatcher::normalize($query)) === 1) {
                foreach ($current as $id) {
                    $selected[$id] ??= $this->source($byId[$id], 'primary', 'current_batch');
                }
            }

            return ['selected' => array_values($selected), 'complete' => true, 'ambiguous_ids' => []];
        }
        if ($constraints->ordinalPosition !== null && $selected !== []) {
            return ['selected' => array_values($selected), 'complete' => true, 'ambiguous_ids' => []];
        }

        $pastReference = preg_match('/\b(?:предыдущ\p{L}*|прошл\p{L}*|ранее|тот|тому|вернемся|вернёмся)\b/u', ExpertContextLexicalMatcher::normalize($query)) === 1;
        if ($current === [] && $pastReference && count($state->lastResolvedSourceSet) === 1) {
            $id = $state->lastResolvedSourceSet[0];
            if (isset($byId[$id]) && ! in_array($id, $constraints->hardExcludedIds, true)) {
                return ['selected' => [$this->source($byId[$id], 'primary', 'recent_source_reference')], 'complete' => true, 'ambiguous_ids' => []];
            }
        }

        $nonCurrentMatch = false;
        foreach ($pool->candidates as $candidate) {
            if (! in_array($candidate->materialId, $current, true)
                && ExpertContextLexicalMatcher::score($candidate, $query) > 0) {
                $nonCurrentMatch = true;
                break;
            }
        }
        if ($current !== [] && ! $pastReference && ! $nonCurrentMatch && ! $intent->crossDocument) {
            foreach ($current as $id) {
                $selected[$id] ??= $this->source($byId[$id], 'primary', 'current_batch');
            }

            return ['selected' => array_values($selected), 'complete' => true, 'ambiguous_ids' => []];
        }
        if ($current !== [] && $intent->crossDocument && ! $pastReference && ! $nonCurrentMatch) {
            foreach ($current as $id) {
                $selected[$id] ??= $this->source($byId[$id], 'primary', 'current_batch');
            }

            return ['selected' => array_values($selected), 'complete' => true, 'ambiguous_ids' => []];
        }

        return ['selected' => array_values($selected), 'complete' => false, 'ambiguous_ids' => []];
    }

    /** @return array{material_id: string, role: string, origin: string, reason_code: string} */
    private function source(ExpertContextCandidate $candidate, string $role, string $reason): array
    {
        return [
            'material_id' => $candidate->materialId,
            'role' => $role,
            'origin' => $candidate->origins[0] ?? 'project',
            'reason_code' => $reason,
        ];
    }
}
