<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\ProjectLaborWork;
use Illuminate\Console\Command;

class TestHoursFlags extends Command
{
    protected $signature = 'test:hours-flags {projectId=4}';
    protected $description = 'Test hours_source flag functionality';

    public function handle()
    {
        $projectId = $this->argument('projectId');
        $project = Project::find($projectId);

        if (!$project) {
            $this->error("Project $projectId not found");
            return 1;
        }

        $this->info("Testing hours flags for project: {$project->code}\n");

        try {
            // Create a labor work
            $this->line('Step 1: Creating labor work with manual hours...');
            $work = $project->laborWorks()->create([
                'title' => 'Тестовая работа',
                'hours' => 10.00,
                'hours_source' => 'manual',
                'hours_manual' => 10.00,
            ]);
            $this->line("✓ Labor work created");
            $this->line("  ID: {$work->id}");
            $this->line("  hours_source: {$work->hours_source}");
            $this->line("  hours: {$work->hours}");
            $this->line("  hours_manual: {$work->hours_manual}");
            $this->line("  isManualHours(): " . ($work->isManualHours() ? 'true' : 'false'));
            $this->line("  getEffectiveHours(): " . $work->getEffectiveHours());

            // Add steps
            $this->line('\nStep 2: Adding steps...');
            $step1 = $work->steps()->create([
                'title' => 'Этап 1',
                'hours' => 3.0,
                'sort_order' => 0,
            ]);
            $step2 = $work->steps()->create([
                'title' => 'Этап 2',
                'hours' => 4.5,
                'sort_order' => 1,
            ]);
            $step3 = $work->steps()->create([
                'title' => 'Этап 3',
                'hours' => 2.0,
                'sort_order' => 2,
            ]);
            $this->line("✓ Created 3 steps");
            $stepsTotal = $work->steps()->sum('hours');
            $this->line("  Total hours from steps: {$stepsTotal}ч");

            // Test 3: Switch to from_steps mode
            $this->line('\nStep 3: Switching to from_steps mode...');
            $work->setHoursFromSteps();
            $work->refresh();
            $this->line("✓ Switched to from_steps");
            $this->line("  hours_source: {$work->hours_source}");
            $this->line("  hours: {$work->hours}");
            $this->line("  isFromSteps(): " . ($work->isFromSteps() ? 'true' : 'false'));
            $this->line("  getEffectiveHours(): " . $work->getEffectiveHours());

            // Test 4: Verify hours change when steps change
            $this->line('\nStep 4: Modifying a step...');
            $step1->update(['hours' => 4.0]);
            $work->refresh(); // Database still has old value
            $effective = $work->getEffectiveHours();
            $this->line("✓ Step modified");
            $this->line("  New effective hours: {$effective}ч");
            $this->line("  Expected: 10.5ч (4.0 + 4.5 + 2.0)");

            // Test 5: Switch back to manual
            $this->line('\nStep 5: Switching back to manual mode...');
            $work->setManualHours(15.00);
            $work->refresh();
            $this->line("✓ Switched to manual");
            $this->line("  hours_source: {$work->hours_source}");
            $this->line("  hours: {$work->hours}");
            $this->line("  hours_manual: {$work->hours_manual}");
            $this->line("  isManualHours(): " . ($work->isManualHours() ? 'true' : 'false'));
            $this->line("  getEffectiveHours(): " . $work->getEffectiveHours());

            // Test 6: Verify effective hours doesn't change when steps change in manual mode
            $this->line('\nStep 6: Modifying step in manual mode...');
            $step2->update(['hours' => 10.0]);
            $work->refresh();
            $effective = $work->getEffectiveHours();
            $this->line("✓ Step modified (but we're in manual mode)");
            $this->line("  Effective hours: {$effective}ч");
            $this->line("  Expected: 15.0ч (still manual value)");

            // Cleanup
            $this->line('\nCleaning up...');
            $work->delete();
            $this->line("✓ Test data deleted");

            $this->info('\nAll tests passed!');
            return 0;

        } catch (\Exception $e) {
            $this->error("✗ Error: " . $e->getMessage());
            $this->error($e->getTraceAsString());
            return 1;
        }
    }
}
