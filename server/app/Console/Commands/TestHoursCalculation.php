<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\ProjectLaborWork;
use App\Services\LaborWorkHoursCalculator;
use Illuminate\Console\Command;

class TestHoursCalculation extends Command
{
    protected $signature = 'test:hours-calculation {projectId=4}';
    protected $description = 'Test automatic hours calculation logic';

    public function handle()
    {
        $projectId = $this->argument('projectId');
        $project = Project::find($projectId);

        if (!$project) {
            $this->error("Project $projectId not found");
            return 1;
        }

        $calculator = app(LaborWorkHoursCalculator::class);

        $this->info("Testing hours calculation for project: {$project->code}\n");

        try {
            // Test 1: Create work without steps - should be manual
            $this->line('Test 1: Create work without steps (manual mode)');
            $work = $project->laborWorks()->create([
                'title' => 'Работа без подопераций',
                'hours' => 8.0,
            ]);
            $work->refresh();
            
            $this->line("✓ Work created: {$work->title}");
            $this->line("  hours_source: {$work->hours_source}");
            $this->line("  hours: {$work->hours}");
            $this->line("  hours_manual: {$work->hours_manual}");
            $this->line("  Expected: hours_source='manual'");

            if ($work->hours_source !== 'manual') {
                throw new \Exception("Expected hours_source='manual', got '{$work->hours_source}'");
            }

            // Test 2: Add steps - should automatically switch to from_steps
            $this->line('\nTest 2: Add steps (auto switch to from_steps)');
            $step1 = $work->steps()->create([
                'title' => 'Этап 1',
                'hours' => 3.0,
                'sort_order' => 0,
            ]);
            
            $work->refresh();
            $this->line("✓ Step created");
            $this->line("  hours_source: {$work->hours_source}");
            $this->line("  hours: {$work->hours}");
            $this->line("  Expected: hours_source='from_steps', hours=3.0");

            if ($work->hours_source !== 'from_steps') {
                throw new \Exception("Expected hours_source='from_steps' after adding step");
            }
            if ($work->hours != 3.0) {
                throw new \Exception("Expected hours=3.0, got {$work->hours}");
            }

            // Test 3: Add more steps - hours should increase
            $this->line('\nTest 3: Add another step (hours should increase)');
            $step2 = $work->steps()->create([
                'title' => 'Этап 2',
                'hours' => 4.5,
                'sort_order' => 1,
            ]);
            
            $work->refresh();
            $this->line("✓ Another step created");
            $this->line("  hours: {$work->hours}");
            $this->line("  Expected: hours=7.5");

            if ($work->hours != 7.5) {
                throw new \Exception("Expected hours=7.5, got {$work->hours}");
            }

            // Test 4: Update step - hours should recalculate
            $this->line('\nTest 4: Update step hours (parent should recalculate)');
            $step1->update(['hours' => 5.0]);
            
            $work->refresh();
            $this->line("✓ Step updated");
            $this->line("  hours: {$work->hours}");
            $this->line("  Expected: hours=9.5");

            if ($work->hours != 9.5) {
                throw new \Exception("Expected hours=9.5, got {$work->hours}");
            }

            // Test 5: Delete step - should recalculate
            $this->line('\nTest 5: Delete a step (hours should decrease)');
            $step1->delete();
            
            $work->refresh();
            $this->line("✓ Step deleted");
            $this->line("  hours: {$work->hours}");
            $this->line("  Expected: hours=4.5");

            if ($work->hours != 4.5) {
                throw new \Exception("Expected hours=4.5, got {$work->hours}");
            }

            // Test 6: Delete last step - should switch back to manual
            $this->line('\nTest 6: Delete last step (switch to manual)');
            $step2->delete();
            
            $work->refresh();
            $this->line("✓ Last step deleted");
            $this->line("  hours_source: {$work->hours_source}");
            $this->line("  hours: {$work->hours}");
            $this->line("  Expected: hours_source='manual'");

            if ($work->hours_source !== 'manual') {
                throw new \Exception("Expected hours_source='manual' after deleting last step");
            }

            // Test 7: Verify manual mode persists when no steps
            $this->line('\nTest 7: Verify manual mode with no steps');
            $status = $calculator->getStatus($work);
            $this->line("✓ Status checked");
            $this->line("  hours_source: {$status['hours_source']}");
            $this->line("  effective_hours: {$status['effective_hours']}");
            $this->line("  steps_count: {$status['steps_count']}");
            $this->line("  Expected: hours_source='manual', steps_count=0");

            if ($status['hours_source'] !== 'manual' || $status['steps_count'] != 0) {
                throw new \Exception("Expected manual mode with 0 steps");
            }

            // Test 8: Add steps and verify auto mode switch
            $this->line('\nTest 8: Add multiple steps and verify from_steps mode');
            $step1 = $work->steps()->create([
                'title' => 'Этап 1',
                'hours' => 5.0,
                'sort_order' => 0,
            ]);
            
            $step2 = $work->steps()->create([
                'title' => 'Этап 2',
                'hours' => 3.0,
                'sort_order' => 1,
            ]);
            
            $work->refresh();
            $status = $calculator->getStatus($work);
            $this->line("✓ Steps added");
            $this->line("  hours_source: {$status['hours_source']}");
            $this->line("  hours: {$status['hours']}");
            $this->line("  steps_total: {$status['steps_total']}");
            $this->line("  Expected: hours_source='from_steps', hours=8.0, steps_total=8.0");

            if ($status['hours_source'] !== 'from_steps' || $status['hours'] != 8.0) {
                throw new \Exception("Expected from_steps mode with 8.0 hours");
            }

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
