<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Models\Expert\ExpertConversation;

final class ExpertContextPlanner
{
    public function __construct(private readonly ExpertProjectCoreContextBuilder $core) {}

    /** @param list<string> $currentIds @param list<string> $historicalIds */
    public function plan(ExpertConversation $conversation, string $message, array $currentIds, array $historicalIds): ExpertContextPack
    {
        $active = $conversation->activeMaterials()->get(['expert_project_materials.public_id', 'expert_project_materials.original_name']);
        $activeIds = $active->pluck('public_id')->all();
        $currentIds = array_values(array_unique($currentIds));
        $historicalIds = array_values(array_diff(array_unique($historicalIds), $currentIds));
        $explicitActive = [];
        $normalized = $this->normalize($message);
        foreach ($active as $material) {
            $stem = preg_replace('/\.[^.]+$/u', '', $this->normalize($material->original_name)) ?? '';
            $fullName = $this->normalize($material->original_name);
            if (($fullName !== '' && str_contains($normalized, $fullName)) || (mb_strlen($stem) >= 3 && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($stem, '/').'(?![\p{L}\p{N}])/u', $normalized) === 1)) {
                $explicitActive[] = $material->public_id;
            }
        }

        $exhaustive = preg_match('/\b(все|всех|всем|кажд(?:ый|ом|ого)|полный перечень|дословно|перечисли|в каждом|найди все|все противоречия|все реквизиты)\b/u', $normalized) === 1;
        $exhaustive = $exhaustive || preg_match('/\b(?:какие|кто|сколько)\b.*\bв этих документах\b/u', $normalized) === 1;
        $plural = preg_match('/\b(документы|документах|материалах|материалы|файлах|файлы|экспертизах)\b/u', $normalized) === 1;
        $targeted = $explicitActive !== [] || $historicalIds !== [];
        $generic = preg_match('/\b(документ|документе|нем|экспертиза|экспертизе|там|присутствовал|вопросы|гост|исследовал|осмотре)\b/u', $normalized) === 1;
        $ambiguous = $currentIds === [] && ! $targeted && count($activeIds) > 1 && ! $plural && ! $exhaustive
            && preg_match('/\b(этот документ|это за документ|в нем|что это|что там)\b/u', $normalized) === 1;

        $selectedActive = [];
        if ($targeted) {
            $selectedActive = $explicitActive;
        } elseif ($currentIds === []) {
            if (count($activeIds) === 1 && ($generic || $normalized !== '')) {
                $selectedActive = $activeIds;
            } elseif (! $ambiguous && $normalized !== '') {
                $selectedActive = $activeIds;
            }
        }
        $selectedActive = array_values(array_diff(array_unique($selectedActive), $currentIds, $historicalIds));
        $resolved = array_values(array_unique([...$currentIds, ...$selectedActive, ...$historicalIds]));
        $scope = count($resolved) <= 1 ? 'single' : ($targeted || $currentIds !== [] ? 'targeted_multi' : ($exhaustive ? 'exhaustive_multi' : 'retrieval_multi'));
        $requiresPipeline = count($resolved) > 4;

        return new ExpertContextPack(
            $this->core->build($conversation->project->loadMissing('researchObjects')),
            $currentIds,
            $activeIds,
            $historicalIds,
            $resolved,
            $scope,
            $exhaustive ? 'exhaustive' : 'focused',
            $requiresPipeline,
            ['material_count' => count($resolved), 'requires_material_disambiguation' => $ambiguous],
            $conversation->messages()->whereIn('role', ['user', 'assistant'])->reorder()->orderByDesc('id')->limit(max(0, (int) config('expert.chat.history_limit', 20)))->get(['public_id', 'role'])->reverse()->values()->map(static fn ($item): array => [
                'source_type' => 'CHAT_HISTORY',
                'message_id' => $item->public_id,
                'role' => $item->role,
            ])->all(),
        );
    }

    private function normalize(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower(str_replace('ё', 'е', $value), 'UTF-8')) ?? '');
    }
}
