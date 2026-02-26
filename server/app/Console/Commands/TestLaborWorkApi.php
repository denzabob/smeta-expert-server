<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\ProjectLaborWork;
use Illuminate\Console\Command;

class TestLaborWorkApi extends Command
{
    protected $signature = 'test:labor-work-api';
    protected $description = 'Test labor work API creation';

    public function handle()
    {
        $this->info('Testing labor work API...');

        $project = Project::find(4);
        if (!$project) {
            $this->error('Project 4 not found');
            return;
        }

        // Test 1: Create work without position_profile_id via simulated API
        $this->line('');
        $this->info('Test 1: API create work without position_profile_id');
        try {
            $data = [
                'title' => 'API Test Work (no profile)',
                'hours' => 5,
                'position_profile_id' => null,
            ];
            
            $work = ProjectLaborWork::create(array_merge($data, ['project_id' => $project->id]));
            
            $this->line("✓ Work created via API: {$work->title} (ID: {$work->id})");
            $this->line("  - position_profile_id: " . ($work->position_profile_id ?? 'null'));
            $this->line("  - rate_per_hour: " . ($work->rate_per_hour ?? 'null'));
            
            $work->delete();
            $this->line("✓ Cleaned up");
        } catch (\Exception $e) {
            $this->error("✗ Failed: " . $e->getMessage());
            return 1;
        }

        $this->info('All tests passed!');
        return 0;
    }
}
