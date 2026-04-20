<?php

namespace App\Console\Commands;

use App\Models\LaborProfile;
use App\Models\ProjectLaborWork;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class EnsureLaborProfilesForWorks extends Command
{
    protected $signature = 'labor:ensure-work-profiles
                            {--project= : Limit processing to a single project ID}
                            {--dry-run : Show what would be created without writing to the database}
                            {--rerun-backfill : Rerun labor:backfill-work-profiles after creation}';

    protected $description = 'Create missing labor profiles for unresolved project labor works and optionally rerun profile backfill';

    public function handle(): int
    {
        $projectId = $this->option('project');
        $dryRun = (bool) $this->option('dry-run');
        $rerunBackfill = (bool) $this->option('rerun-backfill');

        $works = ProjectLaborWork::query()
            ->with([
                'project:id,user_id',
                'positionProfile:id,name',
            ])
            ->when($projectId !== null, fn ($query) => $query->where('project_id', (int) $projectId))
            ->whereNull('labor_profile_id')
            ->whereNotNull('position_profile_id')
            ->orderBy('project_id')
            ->orderBy('id')
            ->get();

        if ($works->isEmpty()) {
            $this->info('No unresolved project labor works found.');
            return self::SUCCESS;
        }

        $requiredProfiles = [];

        foreach ($works as $work) {
            $userId = $work->project?->user_id;
            $profileName = trim((string) ($work->positionProfile?->name ?? ''));

            if (!$userId || $profileName === '') {
                continue;
            }

            $key = $userId . '|' . $this->normalizeName($profileName);

            if (!isset($requiredProfiles[$key])) {
                $requiredProfiles[$key] = [
                    'user_id' => $userId,
                    'title' => $profileName,
                    'normalized_title' => $this->normalizeName($profileName),
                    'source_position_profile_ids' => [],
                    'work_ids' => [],
                ];
            }

            $requiredProfiles[$key]['source_position_profile_ids'][$work->position_profile_id] = true;
            $requiredProfiles[$key]['work_ids'][] = $work->id;
        }

        $createdProfiles = [];
        $skippedProfiles = [];

        foreach ($requiredProfiles as $item) {
            $matches = LaborProfile::query()
                ->where('user_id', $item['user_id'])
                ->get(['id', 'user_id', 'title'])
                ->filter(function (LaborProfile $profile) use ($item): bool {
                    return $this->normalizeName($profile->title) === $item['normalized_title'];
                })
                ->values();

            if ($matches->count() === 1) {
                $skippedProfiles[] = [
                    'user_id' => $item['user_id'],
                    'title' => $item['title'],
                    'reason' => 'already_exists',
                    'existing_profile_id' => $matches->first()->id,
                ];
                continue;
            }

            if ($matches->count() > 1) {
                $skippedProfiles[] = [
                    'user_id' => $item['user_id'],
                    'title' => $item['title'],
                    'reason' => 'ambiguous_existing_profiles',
                    'existing_profile_ids' => $matches->pluck('id')->values()->all(),
                ];

                Log::warning('labor_ensure_profiles_ambiguous_existing', [
                    'user_id' => $item['user_id'],
                    'title' => $item['title'],
                    'existing_profile_ids' => $matches->pluck('id')->values()->all(),
                ]);
                continue;
            }

            if ($dryRun) {
                $createdProfiles[] = [
                    'id' => null,
                    'user_id' => $item['user_id'],
                    'title' => $item['title'],
                    'is_active' => true,
                    'mode' => 'dry-run',
                ];
                continue;
            }

            $sortOrder = ((int) LaborProfile::query()
                ->where('user_id', $item['user_id'])
                ->max('sort_order')) + 1;

            $profile = LaborProfile::create([
                'user_id' => $item['user_id'],
                'title' => $item['title'],
                'description' => null,
                'is_active' => true,
                'sort_order' => $sortOrder,
            ]);

            $createdProfiles[] = [
                'id' => $profile->id,
                'user_id' => $profile->user_id,
                'title' => $profile->title,
                'is_active' => (bool) $profile->is_active,
                'mode' => 'created',
            ];
        }

        $this->info(sprintf('Required unique profiles: %d', count($requiredProfiles)));
        $this->info(sprintf('Created profiles: %d', count($createdProfiles)));
        $this->info(sprintf('Skipped profiles: %d', count($skippedProfiles)));

        if (!empty($createdProfiles)) {
            $this->newLine();
            $this->info('Created profiles:');
            $this->line(json_encode($createdProfiles, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        if (!empty($skippedProfiles)) {
            $this->newLine();
            $this->warn('Skipped profile creations:');
            $this->line(json_encode($skippedProfiles, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        if ($rerunBackfill && $projectId !== null) {
            $this->newLine();
            $this->info(sprintf(
                'Rerunning labor:backfill-work-profiles for project %s%s...',
                $projectId,
                $dryRun ? ' (dry-run)' : ''
            ));

            $arguments = [
                '--project' => (int) $projectId,
            ];

            if ($dryRun) {
                $arguments['--dry-run'] = true;
            }

            Artisan::call('labor:backfill-work-profiles', $arguments);
            $this->line(Artisan::output());
        }

        return self::SUCCESS;
    }

    private function normalizeName(?string $value): string
    {
        $value = trim((string) $value);
        $value = mb_strtolower($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return $value;
    }
}
