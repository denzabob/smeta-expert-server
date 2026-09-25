<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Models\Expert\ExpertConversation;
use App\Models\Expert\ExpertMessage;
use App\Models\Expert\ExpertProjectMaterial;
use Illuminate\Support\Facades\Log;

/** Discovers descriptors only. Candidate presence never implies source selection. */
final class ExpertContextCandidateProvider
{
    public function __construct(
        private readonly ExpertConversationMaterialStateBuilder $materialState,
        private readonly ExpertMaterialIdentityService $identities,
    ) {}

    /** @param list<string> $currentIds @param list<string> $historicalHints */
    public function discover(
        ExpertConversation $conversation,
        string $query,
        array $currentIds,
        array $historicalHints = [],
        ?ExpertMessage $currentMessage = null,
        string $coverageMode = ExpertTaskIntent::FOCUSED,
        ?ExpertConversationMaterialState $state = null,
    ): ExpertContextCandidatePool {
        $startedAt = microtime(true);
        $currentIds = array_values(array_unique($currentIds));
        $state ??= $this->materialState->build($conversation, $currentMessage);
        $origins = [];
        $add = static function (array $ids, string $origin) use (&$origins): void {
            foreach ($ids as $id) {
                if (is_string($id) && $id !== '') {
                    $origins[$id][$origin] = true;
                }
            }
        };
        $add($currentIds, 'current');
        $add($historicalHints, 'historical');
        $add($state->lastResolvedSourceSet, 'last_resolved');
        $add($state->lastPrimarySourceSet, 'last_primary');
        $add($state->lastComparisonSourceSet, 'last_comparison');
        $add($state->activeResearchSet, 'active');
        foreach ($state->recentSourceSets as $sourceSet) {
            $add($sourceSet->materialIds, 'recent');
        }
        foreach ($state->recentAttachmentBatches as $batch) {
            $add($batch->orderedMaterialIds, 'historical');
        }

        $max = max(1, (int) config('expert.context_resolution.max_project_candidates', 40));
        $projectQuery = $conversation->project->materials()->select([
            'id', 'public_id', 'expert_project_id', 'original_name', 'storage_path', 'mime_type', 'extension', 'category', 'status', 'metadata',
        ]);
        if ($coverageMode !== ExpertTaskIntent::EXHAUSTIVE) {
            $tokens = ExpertContextLexicalMatcher::tokens($query);
            if ($tokens !== []) {
                $projectQuery->where(function ($builder) use ($tokens): void {
                    foreach ($tokens as $token) {
                        $pattern = '%'.addcslashes(ExpertContextLexicalMatcher::discoveryFragment($token), '%_\\').'%';
                        $builder->orWhere('original_name', 'like', $pattern)
                            ->orWhereHas('identity', static fn ($identity) => $identity
                                ->where('routing_text', 'like', $pattern)
                                ->orWhere('descriptor', 'like', $pattern));
                    }
                });
            } else {
                $projectQuery->whereRaw('1 = 0');
            }
            $projectQuery->orderByDesc('updated_at')->orderBy('public_id')->limit($max + 1);
        } else {
            $projectQuery->orderBy('public_id');
        }
        $projectMatches = $projectQuery->get();
        $truncated = $coverageMode !== ExpertTaskIntent::EXHAUSTIVE && $projectMatches->count() > $max;
        $add(($coverageMode === ExpertTaskIntent::EXHAUSTIVE ? $projectMatches : $projectMatches->take($max))->pluck('public_id')->all(), 'project');

        $materials = $projectMatches->keyBy('public_id');
        $missing = array_values(array_diff(array_keys($origins), $materials->keys()->all()));
        foreach (array_chunk($missing, 500) as $ids) {
            foreach ($conversation->project->materials()->whereIn('public_id', $ids)
                ->get(['id', 'public_id', 'expert_project_id', 'original_name', 'storage_path', 'mime_type', 'extension', 'category', 'status', 'metadata']) as $material) {
                $materials->put($material->public_id, $material);
            }
        }
        $precedence = ['current', 'last_primary', 'last_comparison', 'last_resolved', 'active', 'recent', 'historical', 'project'];
        $candidates = [];
        $identityLookupMs = 0.0;
        foreach ($origins as $id => $candidateOrigins) {
            /** @var ExpertProjectMaterial|null $material */
            $material = $materials->get($id);
            if ($material === null) {
                continue;
            }
            $identityStartedAt = microtime(true);
            try {
                $identity = $this->identities->forCandidate($material)->toRoutingDescriptor($material);
            } catch (\Throwable $exception) {
                Log::warning('Expert material identity failed.', [
                    'material_public_id' => (string) $material->public_id,
                    'identity_state' => 'failed',
                    'schema_version' => (string) config('expert.context.identity.version', 'v1'),
                    'content_source' => 'filename',
                    'routing_text_chars' => 0,
                    'source_changed' => false,
                    'metadata_changed' => false,
                    'error_code' => 'identity_unavailable',
                    'exception' => $exception::class,
                ]);
                $identity = [
                    'material_id' => (string) $material->public_id,
                    'state' => 'failed',
                    'display_name' => ExpertMaterialPresentationName::resolve($material),
                    'aliases' => [(string) $material->original_name],
                    'mime_type' => (string) $material->mime_type,
                    'extension' => (string) $material->extension,
                    'category' => (string) $material->category,
                    'routing_text' => null,
                    'content_available' => false,
                ];
            }
            $identityLookupMs += (microtime(true) - $identityStartedAt) * 1000;
            $candidates[] = new ExpertContextCandidate(
                (string) $material->public_id,
                (string) $conversation->project->public_id,
                (string) $material->original_name,
                (string) $material->mime_type,
                (string) $material->category,
                array_values(array_filter($precedence, static fn (string $origin): bool => isset($candidateOrigins[$origin]))),
                in_array($id, $currentIds, true) ? array_search($id, $currentIds, true) : null,
                $identity,
                (string) $material->status,
                in_array($id, $currentIds, true) ? $currentMessage?->public_id : null,
            );
        }
        usort($candidates, static fn (ExpertContextCandidate $a, ExpertContextCandidate $b): int => strcmp($a->materialId, $b->materialId));

        return new ExpertContextCandidatePool(
            $candidates,
            new ExpertAttachmentBatch($currentMessage?->public_id, $currentIds),
            $truncated,
            (int) round((microtime(true) - $startedAt) * 1000),
            (int) round($identityLookupMs),
            $historicalHints,
        );
    }
}
