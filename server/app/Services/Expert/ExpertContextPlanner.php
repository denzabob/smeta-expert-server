<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Models\Expert\ExpertConversation;

final class ExpertContextPlanner
{
    public function __construct(
        private readonly ExpertProjectCoreContextBuilder $core,
        private readonly ExpertTaskIntentResolver $intentResolver,
    ) {}

    /** @param list<string> $currentIds @param list<string> $historicalIds */
    public function plan(
        ExpertConversation $conversation,
        string $message,
        array $currentIds,
        array $historicalIds,
        ?ExpertTaskIntent $intent = null,
    ): ExpertContextPack {
        $active = $conversation->activeMaterials()->get(['expert_project_materials.public_id', 'expert_project_materials.original_name']);
        $activeIds = $active->pluck('public_id')->all();
        $currentIds = array_values(array_unique($currentIds));
        $historicalIds = array_values(array_diff(array_unique($historicalIds), $currentIds));
        $intent ??= $this->intentResolver->resolveForConversation($conversation, $message, $currentIds, $historicalIds);
        $explicitActive = array_values(array_intersect($activeIds, $intent->explicitMaterialIds()));
        $ambiguous = $currentIds === [] && $intent->materialScope === ExpertTaskIntent::AMBIGUOUS;
        $targeted = $explicitActive !== [] || $historicalIds !== []
            || ($intent->crossDocument && $intent->coverageMode === ExpertTaskIntent::FOCUSED);

        $selectedActive = [];
        if ($targeted) {
            $selectedActive = $explicitActive !== []
                ? $explicitActive
                : ($intent->crossDocument && $intent->coverageMode === ExpertTaskIntent::FOCUSED ? $activeIds : []);
        } elseif ($currentIds === []) {
            if (count($activeIds) === 1 && $message !== '') {
                $selectedActive = $activeIds;
            } elseif (! $ambiguous && $message !== '') {
                $selectedActive = $activeIds;
            }
        }
        $selectedActive = array_values(array_diff(array_unique($selectedActive), $currentIds, $historicalIds));
        $resolved = array_values(array_unique([...$currentIds, ...$selectedActive, ...$historicalIds]));
        $scope = $ambiguous
            ? ($intent->coverageMode === ExpertTaskIntent::EXHAUSTIVE ? 'exhaustive_multi' : 'retrieval_multi')
            : (count($resolved) <= 1 ? 'single' : ($targeted || $currentIds !== [] ? 'targeted_multi' : ($intent->coverageMode === ExpertTaskIntent::EXHAUSTIVE ? 'exhaustive_multi' : 'retrieval_multi')));
        // Material count is an input to workload assessment, not a standalone
        // product or execution decision.
        $requiresPipeline = false;

        return new ExpertContextPack(
            $this->core->build($conversation->project->loadMissing('researchObjects')),
            $currentIds,
            $activeIds,
            $historicalIds,
            $resolved,
            $scope,
            $intent->coverageMode,
            $requiresPipeline,
            [
                'material_count' => count($resolved),
                'requires_material_disambiguation' => $ambiguous,
                ...$intent->toMetadata(),
            ],
            $conversation->messages()->whereIn('role', ['user', 'assistant'])->reorder()->orderByDesc('id')->limit(max(0, (int) config('expert.chat.history_limit', 20)))->get(['public_id', 'role'])->reverse()->values()->map(static fn ($item): array => [
                'source_type' => 'CHAT_HISTORY',
                'message_id' => $item->public_id,
                'role' => $item->role,
            ])->all(),
            $intent,
        );
    }
}
