<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\ProjectLaborWork;
use App\Models\PositionProfile;
use App\Services\LaborWorkRateBinder;
use Illuminate\Console\Command;

class TestLaborWorkCreate extends Command
{
    protected $signature = 'test:labor-work-create';
    protected $description = 'Test creating labor work with and without position profile';

    public function handle(LaborWorkRateBinder $rateBinder)
    {
        $project = Project::find(4);
        if (!$project) {
            $this->error('Project 4 not found');
            return;
        }

        $this->info('Testing labor work creation...');

        // Test 1: Create without position_profile_id
        $this->line('');
        $this->info('Test 1: Create work without position_profile_id');
        try {
            $work = ProjectLaborWork::create([
                'project_id' => $project->id,
                'title' => 'Test Work (no profile)',
                'hours' => 10,
                'position_profile_id' => null,
            ]);
            $this->line("✓ Work created: {$work->title} (ID: {$work->id})");
            $this->line("  - position_profile_id: " . ($work->position_profile_id ?? 'null'));
            $this->line("  - rate_per_hour: " . ($work->rate_per_hour ?? 'null'));
            $this->line("  - cost_total: " . ($work->cost_total ?? 'null'));
            $work->delete();
            $this->line("✓ Cleaned up");
        } catch (\Exception $e) {
            $this->error("✗ Failed: " . $e->getMessage());
        }

        // Test 2: Create with position_profile_id
        $this->line('');
        $this->info('Test 2: Create work with position_profile_id');
        try {
            $profile = PositionProfile::first();
            if (!$profile) {
                $this->warning('No position profiles found, skipping test 2');
            } else {
                $work = ProjectLaborWork::create([
                    'project_id' => $project->id,
                    'title' => 'Test Work (with profile)',
                    'hours' => 10,
                    'position_profile_id' => $profile->id,
                ]);
                $this->line("✓ Work created: {$work->title} (ID: {$work->id})");
                $this->line("  - position_profile_id: {$work->position_profile_id}");
                
                // Try to bind rate
                $rateBinder->bindRate($work);
                $work->refresh();
                
                $this->line("✓ Rate binding attempted");
                $this->line("  - rate_per_hour: " . ($work->rate_per_hour ?? 'null'));
                $this->line("  - cost_total: " . ($work->cost_total ?? 'null'));
                $work->delete();
                $this->line("✓ Cleaned up");
            }
        } catch (\Exception $e) {
            $this->error("✗ Failed: " . $e->getMessage());
        }

        $this->info('Tests completed!');
    }
}
