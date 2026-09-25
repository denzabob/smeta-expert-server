<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Models\Expert\ExpertConversation;
use App\Models\Expert\ExpertMessage;
use Illuminate\Support\Facades\Log;

final class ExpertConversationMaterialStateBuilder
{
    public function build(ExpertConversation $conversation, ?ExpertMessage $beforeMessage = null): ExpertConversationMaterialState
    {
        $limit = max(1, (int) config('expert.context.recent_source_sets_limit', 8));
        $messageQuery = static fn () => $conversation->messages()->where('role', 'user')
            ->when($beforeMessage !== null, fn ($query) => $query->where('id', '<', $beforeMessage->id))
            ->reorder()->orderByDesc('id');

        $sourceSets = [];
        // Empty text-only snapshots should not displace the recent source sets,
        // while the number of inspected messages remains bounded.
        $snapshotMessages = $messageQuery()->whereNotNull('metadata->expert_context_snapshot')
            ->limit($limit * 4)->get(['id', 'public_id', 'metadata']);
        foreach ($snapshotMessages as $message) {
            $snapshot = is_array($message->metadata) ? ($message->metadata['expert_context_snapshot'] ?? null) : null;
            if (! is_array($snapshot)) {
                continue;
            }
            $sourceSet = ExpertConversationSourceSet::fromSnapshot((string) $message->public_id, $snapshot);
            if ($sourceSet->materialIds !== []) {
                $sourceSets[] = $sourceSet;
                if (count($sourceSets) >= $limit) {
                    break;
                }
            }
        }

        $batchMessages = $messageQuery()->whereHas('attachments')->with('attachments')
            ->limit($limit)->get(['id', 'public_id', 'created_at']);
        $batches = $batchMessages->map(static fn (ExpertMessage $message): ExpertAttachmentBatch => new ExpertAttachmentBatch(
            (string) $message->public_id,
            $message->attachments->pluck('material_public_id_snapshot')->all(),
            $message->created_at?->toIso8601String(),
        ))->all();

        $last = $sourceSets[0] ?? null;
        $active = $conversation->activeMaterials()->pluck('expert_project_materials.public_id')->all();
        $state = new ExpertConversationMaterialState(
            $batches[0] ?? null,
            $last?->materialIds ?? [],
            $last?->primaryIds ?? [],
            $last !== null && $last->crossDocument
                ? array_values(array_unique([...$last->primaryIds, ...$last->comparisonIds]))
                : [],
            $sourceSets,
            $active,
            $batches,
        );

        Log::info('Expert conversation material state resolved', [
            'conversation_public_id' => (string) $conversation->public_id,
            'current_batch_count' => count($state->lastCurrentBatch?->orderedMaterialIds ?? []),
            'active_candidate_count' => count($state->activeResearchSet),
            'recent_source_set_count' => count($state->recentSourceSets),
            'last_resolved_count' => count($state->lastResolvedSourceSet),
            'last_primary_count' => count($state->lastPrimarySourceSet),
            'last_comparison_count' => count($state->lastComparisonSourceSet),
        ]);

        return $state;
    }
}
