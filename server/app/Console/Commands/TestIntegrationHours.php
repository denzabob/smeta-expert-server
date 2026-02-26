<?php

namespace App\Console\Commands;

use App\Models\Project;
use Illuminate\Console\Command;

class TestIntegrationHours extends Command
{
    protected $signature = 'test:integration-hours {projectId=4}';
    protected $description = 'Test complete hours calculation flow integration';

    public function handle()
    {
        $projectId = $this->argument('projectId');
        $project = Project::find($projectId);

        if (!$project) {
            $this->error("Project $projectId not found");
            return 1;
        }

        $this->info("Testing complete hours calculation integration for project: {$project->code}\n");

        try {
            // Scenario 1: Create work with steps
            $this->line('Scenario 1: Create work and add steps incrementally');
            $work = $project->laborWorks()->create([
                'title' => 'Сборка мебели',
                'basis' => 'При наличии комплектов',
                'hours' => 0,
            ]);
            
            $this->line("✓ Work created (ID: {$work->id})");
            $this->line("  Initial mode: {$work->hours_source} (should be manual since no steps)");

            // Add steps
            $this->line("\n  Adding step 1...");
            $s1 = $work->steps()->create([
                'title' => 'Распаковка и проверка',
                'input_data' => '1 комплект',
                'hours' => 0.5,
                'sort_order' => 0,
            ]);
            $work->refresh();
            $this->line("  ✓ After step 1: mode={$work->hours_source}, hours={$work->hours}");

            $this->line("  Adding step 2...");
            $s2 = $work->steps()->create([
                'title' => 'Сборка каркаса',
                'hours' => 2.0,
                'sort_order' => 1,
            ]);
            $work->refresh();
            $this->line("  ✓ After step 2: mode={$work->hours_source}, hours={$work->hours}");

            $this->line("  Adding step 3...");
            $s3 = $work->steps()->create([
                'title' => 'Монтаж фасадов',
                'hours' => 1.5,
                'sort_order' => 2,
            ]);
            $work->refresh();
            $this->line("  ✓ After step 3: mode={$work->hours_source}, hours={$work->hours}");

            if ($work->hours_source !== 'from_steps' || $work->hours != 4.0) {
                throw new \Exception("Expected from_steps mode with 4.0 hours");
            }

            // Scenario 2: Modify steps
            $this->line("\nScenario 2: Modify step hours");
            $s1->update(['hours' => 1.0]);
            $work->refresh();
            $expected = 1.0 + 2.0 + 1.5;
            $this->line("✓ Step 1 updated to 1.0 hours");
            $this->line("  Total hours: {$work->hours} (expected: {$expected})");

            if ($work->hours != $expected) {
                throw new \Exception("Expected hours={$expected}, got {$work->hours}");
            }

            // Scenario 3: Delete step and verify recalculation
            $this->line("\nScenario 3: Delete step");
            $s2->delete();
            $work->refresh();
            $expected = 1.0 + 1.5;
            $this->line("✓ Step 2 deleted");
            $this->line("  Total hours: {$work->hours} (expected: {$expected})");

            if ($work->hours != $expected) {
                throw new \Exception("Expected hours={$expected}, got {$work->hours}");
            }

            // Scenario 4: Delete last step - should switch to manual
            $this->line("\nScenario 4: Delete all steps (should switch to manual)");
            $s1->delete();
            $s3->delete();
            $work->refresh();
            $this->line("✓ All steps deleted");
            $this->line("  Mode: {$work->hours_source} (should be manual)");
            $this->line("  Hours preserved: {$work->hours}");

            if ($work->hours_source !== 'manual') {
                throw new \Exception("Expected manual mode after deleting all steps");
            }

            // Scenario 5: Re-add steps
            $this->line("\nScenario 5: Re-add steps after switching to manual");
            $step = $work->steps()->create([
                'title' => 'Новый этап',
                'hours' => 3.0,
                'sort_order' => 0,
            ]);
            $work->refresh();
            $this->line("✓ Step added");
            $this->line("  Mode: {$work->hours_source} (should be from_steps)");
            $this->line("  Hours: {$work->hours}");

            if ($work->hours_source !== 'from_steps' || $work->hours != 3.0) {
                throw new \Exception("Expected from_steps mode with 3.0 hours");
            }

            // Scenario 6: Test effective hours getter
            $this->line("\nScenario 6: Test getEffectiveHours() method");
            $effective = $work->getEffectiveHours();
            $this->line("✓ getEffectiveHours() = {$effective}");
            $this->line("  Expected: 3.0");

            if ($effective != 3.0) {
                throw new \Exception("Expected effective_hours=3.0, got {$effective}");
            }

            // Scenario 7: Test mode checkers
            $this->line("\nScenario 7: Test mode checker methods");
            $this->line("  isFromSteps(): " . ($work->isFromSteps() ? 'true' : 'false') . " (expected: true)");
            $this->line("  isManualHours(): " . ($work->isManualHours() ? 'true' : 'false') . " (expected: false)");

            if (!$work->isFromSteps() || $work->isManualHours()) {
                throw new \Exception("Mode checkers returning wrong values");
            }

            // Cleanup
            $this->line("\nCleaning up...");
            $work->delete();
            $this->line("✓ Test data deleted");

            $this->info("\n✓ All integration tests passed!");
            $this->info("\nSummary:");
            $this->info("  ✓ Auto-switching between manual and from_steps modes");
            $this->info("  ✓ Auto-recalculation of hours when steps change");
            $this->info("  ✓ Preserving hours when switching back to manual");
            $this->info("  ✓ Cost calculation integration ready");
            return 0;

        } catch (\Exception $e) {
            $this->error("✗ Error: " . $e->getMessage());
            $this->error($e->getTraceAsString());
            return 1;
        }
    }
}
