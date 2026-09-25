<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Models\Expert\ExpertConversation;
use App\Models\Expert\ExpertMessage;

final class ExpertContextPlanner
{
    public function __construct(
        private readonly ExpertProjectCoreContextBuilder $core,
        private readonly ExpertTaskIntentResolver $intentResolver,
        private readonly ExpertContextCandidateProvider $candidates,
        private readonly ExpertConversationMaterialStateBuilder $materialState,
        private readonly ExpertContextResolver $resolver,
    ) {}

    /** @param list<string> $currentIds @param list<string> $historicalIds */
    public function plan(
        ExpertConversation $conversation,
        string $message,
        array $currentIds,
        array $historicalIds,
        ?ExpertTaskIntent $intent = null,
        ?ExpertMessage $currentMessage = null,
    ): ExpertContextPack {
        $state = $this->materialState->build($conversation, $currentMessage);
        $activeIds = $state->activeResearchSet;
        $currentIds = array_values(array_unique($currentIds));
        $historicalIds = array_values(array_diff(array_unique($historicalIds), $currentIds));
        if ($currentIds !== [] && $conversation->project->materials()->whereIn('public_id', $currentIds)->count() !== count($currentIds)) {
            throw ExpertMaterialContextException::notFound();
        }
        $intent ??= $this->intentResolver->resolveForConversation($conversation, $message, $currentIds, $historicalIds);
        $discoveryStartedAt = microtime(true);
        $candidatePool = $this->candidates->discover(
            $conversation,
            $message,
            $currentIds,
            $historicalIds,
            $currentMessage,
            coverageMode: $intent->coverageMode,
            state: $state,
        );
        $discoveryMs = (int) round((microtime(true) - $discoveryStartedAt) * 1000);
        $resolution = $this->resolver->resolve($conversation, $message, $intent, $state, $candidatePool);
        $resolved = array_column($resolution->selected, 'material_id');
        $selectedCurrent = array_values(array_intersect($currentIds, $resolved));
        $selectedHistorical = [];
        foreach ($resolution->selected as $source) {
            if (in_array($source['material_id'], $selectedCurrent, true)) {
                continue;
            }
            if ($source['origin'] !== 'active' || in_array($source['material_id'], $historicalIds, true)) {
                $selectedHistorical[] = $source['material_id'];
            }
        }

        return new ExpertContextPack(
            $this->core->build($conversation->project->loadMissing('researchObjects')),
            $selectedCurrent,
            $activeIds,
            $selectedHistorical,
            $resolved,
            $resolution->scope,
            $resolution->coverageMode,
            false,
            [
                'material_count' => count($resolved),
                'requires_material_disambiguation' => $resolution->ambiguous,
                'ambiguity_candidates' => $resolution->ambiguousCandidates,
                'candidate_discovery_ms' => $discoveryMs,
                'candidate_file_bytes_read' => 0,
                ...$intent->toMetadata(),
            ],
            $conversation->messages()->whereIn('role', ['user', 'assistant'])->reorder()->orderByDesc('id')->limit(max(0, (int) config('expert.chat.history_limit', 20)))->get(['public_id', 'role'])->reverse()->values()->map(static fn ($item): array => [
                'source_type' => 'CHAT_HISTORY',
                'message_id' => $item->public_id,
                'role' => $item->role,
            ])->all(),
            $intent,
            $candidatePool->currentBatch,
            $candidatePool,
            $resolution,
        );
    }
}
