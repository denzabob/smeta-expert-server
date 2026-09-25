<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Models\Expert\ExpertConversation;
use UnexpectedValueException;

final class ExpertContextResolutionValidator
{
    private const ROLES = ['primary', 'comparison', 'supporting'];

    private const REASONS = [
        'current_batch', 'explicit_user_selection', 'exact_filename_match',
        'exact_alias_match', 'recent_source_reference', 'semantic_identity_match',
        'semantic_context_match', 'active_candidate_match', 'ordinal_reference',
        'project_exhaustive',
        'conversational_focus', 'focus_shift',
    ];

    /** @param array<string, mixed> $response @param list<ExpertContextCandidate> $candidates @return array{selected: list<array{material_id: string, role: string, origin: string, reason_code: string}>, ambiguous: bool, ambiguous_ids: list<string>, confidence: float} */
    public function validate(array $response, array $candidates, ExpertContextConstraints $constraints, ExpertConversation $conversation): array
    {
        if (! is_bool($response['ambiguous'] ?? null)
            || ! is_array($response['selected'] ?? null)
            || ! array_is_list($response['selected'])
            || ! is_array($response['ambiguous_candidates'] ?? null)
            || ! array_is_list($response['ambiguous_candidates'])
            || ! is_numeric($response['confidence'] ?? null)
            || (float) $response['confidence'] < 0.0
            || (float) $response['confidence'] > 1.0) {
            throw new UnexpectedValueException('Invalid context resolver response shape.');
        }
        $byId = [];
        foreach ($candidates as $candidate) {
            $byId[$candidate->materialId] = $candidate;
        }
        $selected = [];
        $seen = [];
        foreach ($response['selected'] as $entry) {
            if (! is_array($entry)
                || ! is_string($entry['material_id'] ?? null)
                || ! isset($byId[$entry['material_id']])
                || $byId[$entry['material_id']]->availabilityStatus !== 'uploaded'
                || isset($seen[$entry['material_id']])
                || in_array($entry['material_id'], $constraints->hardExcludedIds, true)
                || ! in_array($entry['role'] ?? null, self::ROLES, true)
                || ! in_array($entry['reason_code'] ?? null, self::REASONS, true)) {
                throw new UnexpectedValueException('Invalid context resolver selected material.');
            }
            $id = $entry['material_id'];
            $seen[$id] = true;
            $candidate = $byId[$id];
            $selected[] = [
                'material_id' => $id,
                'role' => $entry['role'],
                'origin' => $candidate->origins[0] ?? 'project',
                'reason_code' => $entry['reason_code'],
            ];
        }
        $ambiguousIds = [];
        foreach ($response['ambiguous_candidates'] as $id) {
            if (! is_string($id) || ! isset($byId[$id]) || $byId[$id]->availabilityStatus !== 'uploaded'
                || in_array($id, $constraints->hardExcludedIds, true) || in_array($id, $ambiguousIds, true)) {
                throw new UnexpectedValueException('Invalid context resolver ambiguity candidate.');
            }
            $ambiguousIds[] = $id;
        }
        $referencedIds = array_values(array_unique([...array_keys($seen), ...$ambiguousIds]));
        $ownedIds = $referencedIds === [] ? [] : $conversation->project->materials()
            ->whereIn('public_id', $referencedIds)->where('status', 'uploaded')->pluck('public_id')->all();
        if (count($ownedIds) !== count($referencedIds)) {
            throw new UnexpectedValueException('Context resolver referenced an unavailable material.');
        }
        if (! $response['ambiguous']) {
            foreach ($constraints->hardIncludedIds as $id) {
                if (! isset($seen[$id])) {
                    throw new UnexpectedValueException('Context resolver dropped a hard included material.');
                }
            }
        }

        return [
            'selected' => $selected,
            'ambiguous' => $response['ambiguous'],
            'ambiguous_ids' => $ambiguousIds,
            'confidence' => (float) $response['confidence'],
        ];
    }
}
