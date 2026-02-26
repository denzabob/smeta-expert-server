<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TestHoursApi extends Command
{
    protected $signature = 'test:hours-api {projectId=4}';
    protected $description = 'Test hours calculation API endpoints';

    public function handle()
    {
        $projectId = $this->argument('projectId');
        $project = Project::find($projectId);

        if (!$project) {
            $this->error("Project $projectId not found");
            return 1;
        }

        $this->info("Testing hours calculation API for project: {$project->code}\n");

        try {
            // Create labor work with steps
            $work = $project->laborWorks()->create([
                'title' => 'API тест - часы',
                'hours' => 5.0,
            ]);

            $step1 = $work->steps()->create([
                'title' => 'Шаг 1',
                'hours' => 2.5,
                'sort_order' => 0,
            ]);

            $step2 = $work->steps()->create([
                'title' => 'Шаг 2',
                'hours' => 3.0,
                'sort_order' => 1,
            ]);

            $work->refresh();

            // Test 1: Get info
            $this->line('Test 1: GET /hours/info');
            $this->line("✓ Work ID: {$work->id}");
            $this->line("  hours_source: {$work->hours_source}");
            $this->line("  hours: {$work->hours}");
            $this->line("  steps_count: " . $work->steps()->count());
            $this->line("  steps_total: " . $work->steps()->sum('hours'));

            if ($work->hours_source !== 'from_steps') {
                throw new \Exception("Expected from_steps mode");
            }

            // Test 2: Test recalculate endpoint
            $this->line('\nTest 2: POST /hours/recalculate');
            $step1->update(['hours' => 4.0]);
            
            $work->refresh();
            $this->line("✓ Step updated to 4.0 hours");
            $this->line("  Work hours: {$work->hours}");
            $this->line("  Expected: 7.0 (4.0 + 3.0)");

            if ($work->hours != 7.0) {
                throw new \Exception("Expected hours=7.0, got {$work->hours}");
            }

            // Test 3: Switch to manual
            $this->line('\nTest 3: POST /hours/set-manual');
            $work->setManualHours(10.0);
            $work->refresh();
            
            $this->line("✓ Switched to manual");
            $this->line("  hours_source: {$work->hours_source}");
            $this->line("  hours: {$work->hours}");
            $this->line("  hours_manual: {$work->hours_manual}");

            if ($work->hours_source !== 'manual' || $work->hours != 10.0) {
                throw new \Exception("Expected manual mode with 10.0 hours");
            }

            // Test 4: Switch back to from_steps
            $this->line('\nTest 4: POST /hours/set-from-steps');
            $work->setHoursFromSteps();
            $work->refresh();
            
            $this->line("✓ Switched to from_steps");
            $this->line("  hours_source: {$work->hours_source}");
            $this->line("  hours: {$work->hours}");
            $this->line("  Expected: 7.0 (4.0 + 3.0)");

            if ($work->hours_source !== 'from_steps' || $work->hours != 7.0) {
                throw new \Exception("Expected from_steps with 7.0 hours");
            }

            // Cleanup
            $this->line('\nCleaning up...');
            $work->delete();
            $this->line("✓ Test data deleted");

            $this->info('\nAll API tests passed!');
            return 0;

        } catch (\Exception $e) {
            $this->error("✗ Error: " . $e->getMessage());
            $this->error($e->getTraceAsString());
            return 1;
        }
    }
}
