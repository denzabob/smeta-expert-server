<?php

namespace App\Console\Commands;

use App\Models\LaborProfile;
use App\Models\PositionProfile;
use App\Models\ProjectLaborWork;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class BackfillLaborProfilesInWorks extends Command
{
    protected $signature = 'labor:backfill-work-profiles
                            {--dry-run : Show the planned mappings without writing to the database}
                            {--project= : Limit processing to a single project ID}
                            {--chunk=500 : Number of works to process per chunk}';

    protected $description = 'Backfill project_labor_works.labor_profile_id from legacy position_profile_id by matching owner-scoped labor profiles';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $projectId = $this->option('project');
        $chunkSize = max(100, (int) $this->option('chunk'));

        $totals = [
            'total' => 0,
            'migrated' => 0,
            'unresolved' => 0,
            'ambiguous' => 0,
            'already_set' => 0,
            'missing_legacy_profile' => 0,
            'errors' => 0,
        ];

        $unresolvedItems = [];

        $this->info(sprintf(
            'Starting labor profile backfill for project labor works (%s, chunk=%d%s)',
            $dryRun ? 'dry-run' : 'write-mode',
            $chunkSize,
            $projectId ? ", project={$projectId}" : ''
        ));

        $query = ProjectLaborWork::query()
            ->select(['id', 'project_id', 'position_profile_id', 'labor_profile_id'])
            ->with([
                'project:id,user_id',
                'positionProfile:id,name',
            ])
            ->orderBy('id');

        if ($projectId !== null) {
            $query->where('project_id', (int) $projectId);
        }

        $query->chunkById($chunkSize, function (Collection $works) use ($dryRun, &$totals, &$unresolvedItems): void {
            foreach ($works as $work) {
                $totals['total']++;

                try {
                    if ($work->labor_profile_id !== null) {
                        $totals['already_set']++;
                        continue;
                    }

                    if (!$work->position_profile_id || !$work->positionProfile) {
                        $totals['missing_legacy_profile']++;
                        $totals['unresolved']++;
                        $unresolvedItems[] = $this->buildUnresolvedItem(
                            $work,
                            'missing_legacy_profile'
                        );
                        continue;
                    }

                    $userId = $work->project?->user_id;
                    if (!$userId) {
                        $totals['unresolved']++;
                        $unresolvedItems[] = $this->buildUnresolvedItem(
                            $work,
                            'missing_project_owner'
                        );
                        continue;
                    }

                    $legacyName = $work->positionProfile->name;
                    $legacyExact = mb_strtolower(trim($legacyName));
                    $legacyNormalized = $this->normalizeName($legacyName);

                    $matches = LaborProfile::query()
                        ->where('user_id', $userId)
                        ->get(['id', 'title', 'user_id'])
                        ->filter(function (LaborProfile $profile) use ($legacyExact, $legacyNormalized): bool {
                            $candidateExact = mb_strtolower(trim((string) $profile->title));
                            $candidateNormalized = $this->normalizeName($profile->title);

                            return $candidateExact === $legacyExact
                                || $candidateNormalized === $legacyNormalized;
                        })
                        ->values();

                    if ($matches->count() === 1) {
                        $match = $matches->first();

                        if ($dryRun) {
                            $this->line(sprintf(
                                '[DRY-RUN] work #%d -> labor_profile #%d (%s)',
                                $work->id,
                                $match->id,
                                $match->title
                            ));
                        } else {
                            $work->labor_profile_id = $match->id;
                            $work->save();
                        }

                        $totals['migrated']++;
                        continue;
                    }

                    if ($matches->count() > 1) {
                        $totals['ambiguous']++;
                        $totals['unresolved']++;

                        $candidateTitles = $matches->map(fn (LaborProfile $profile) => [
                            'id' => $profile->id,
                            'title' => $profile->title,
                        ])->values()->all();

                        $unresolvedItems[] = $this->buildUnresolvedItem(
                            $work,
                            'ambiguous_match',
                            ['candidates' => $candidateTitles]
                        );

                        Log::warning('labor_work_profile_backfill_ambiguous', [
                            'work_id' => $work->id,
                            'project_id' => $work->project_id,
                            'position_profile_id' => $work->position_profile_id,
                            'position_profile_name' => $work->positionProfile->name,
                            'candidates' => $candidateTitles,
                        ]);

                        continue;
                    }

                    $totals['unresolved']++;
                    $unresolvedItems[] = $this->buildUnresolvedItem(
                        $work,
                        'no_matching_labor_profile'
                    );
                } catch (\Throwable $e) {
                    $totals['errors']++;
                    $totals['unresolved']++;
                    $unresolvedItems[] = $this->buildUnresolvedItem(
                        $work,
                        'error',
                        ['error' => $e->getMessage()]
                    );

                    Log::error('labor_work_profile_backfill_error', [
                        'work_id' => $work->id,
                        'project_id' => $work->project_id,
                        'error' => $e->getMessage(),
                    ]);

                    $this->error(sprintf('Work #%d failed: %s', $work->id, $e->getMessage()));
                }
            }
        });

        $this->newLine();
        $this->info(sprintf('Total works: %d', $totals['total']));
        $this->info(sprintf('Migrated: %d', $totals['migrated']));
        $this->info(sprintf('Unresolved: %d', $totals['unresolved']));
        $this->line(sprintf('Already set: %d', $totals['already_set']));
        $this->line(sprintf('Ambiguous: %d', $totals['ambiguous']));
        $this->line(sprintf('Missing legacy profile: %d', $totals['missing_legacy_profile']));
        $this->line(sprintf('Errors: %d', $totals['errors']));

        if (!empty($unresolvedItems)) {
            $this->newLine();
            $this->warn('Unresolved works:');
            $this->line(json_encode($unresolvedItems, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return $totals['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function buildUnresolvedItem(ProjectLaborWork $work, string $reason, array $extra = []): array
    {
        return array_merge([
            'work_id' => $work->id,
            'project_id' => $work->project_id,
            'position_profile_id' => $work->position_profile_id,
            'position_profile_name' => $work->positionProfile?->name,
            'reason' => $reason,
        ], $extra);
    }

    private function normalizeName(?string $value): string
    {
        $value = trim((string) $value);
        $value = mb_strtolower($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return $value;
    }
}
