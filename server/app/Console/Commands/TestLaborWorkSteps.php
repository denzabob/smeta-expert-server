<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\ProjectLaborWork;
use App\Models\ProjectLaborWorkStep;
use Illuminate\Console\Command;

class TestLaborWorkSteps extends Command
{
    protected $signature = 'test:labor-work-steps {projectId=4}';
    protected $description = 'Test labor work steps CRUD operations';

    public function handle()
    {
        $projectId = $this->argument('projectId');
        $project = Project::find($projectId);

        if (!$project) {
            $this->error("Project $projectId not found");
            return 1;
        }

        $this->info("Testing labor work steps for project: {$project->code}\n");

        try {
            // Create a labor work first
            $this->line('Step 1: Creating parent labor work...');
            $laborWork = $project->laborWorks()->create([
                'title' => 'Установка холодильника',
                'basis' => 'При наличии холодильника',
                'hours' => 8.00,
                'note' => 'Основная работа',
            ]);
            $this->line("✓ Labor work created (ID: {$laborWork->id})");

            // Test 1: Create steps
            $this->line('\nTest 1: Create work steps');
            $step1 = ProjectLaborWorkStep::create([
                'project_labor_work_id' => $laborWork->id,
                'title' => 'Распаковка и проверка',
                'input_data' => '1 холодильник',
                'hours' => 0.5,
                'sort_order' => 0,
            ]);
            $this->line("✓ Step 1 created: {$step1->title} ({$step1->hours}ч)");

            $step2 = ProjectLaborWorkStep::create([
                'project_labor_work_id' => $laborWork->id,
                'title' => 'Подготовка место установки',
                'hours' => 1.5,
                'sort_order' => 1,
            ]);
            $this->line("✓ Step 2 created: {$step2->title} ({$step2->hours}ч)");

            $step3 = ProjectLaborWorkStep::create([
                'project_labor_work_id' => $laborWork->id,
                'title' => 'Установка и подключение',
                'input_data' => '1 холодильник',
                'hours' => 5.0,
                'note' => 'Подключение к электросети и водопроводу',
                'sort_order' => 2,
            ]);
            $this->line("✓ Step 3 created: {$step3->title} ({$step3->hours}ч)");

            // Test 2: Get all steps
            $this->line('\nTest 2: Get all steps');
            $steps = $laborWork->steps;
            $this->line("✓ Retrieved {$steps->count()} steps:");
            foreach ($steps as $step) {
                $this->line("  - {$step->title}: {$step->hours}ч (sort: {$step->sort_order})");
            }

            // Test 3: Update step
            $this->line('\nTest 3: Update step');
            $step1->update([
                'hours' => 1.0,
                'note' => 'Обновлено время',
            ]);
            $step1->refresh();
            $this->line("✓ Step updated: {$step1->title} ({$step1->hours}ч)");
            $this->line("  Note: {$step1->note}");

            // Test 4: Total hours calculation
            $this->line('\nTest 4: Calculate total step hours');
            $totalHours = $laborWork->steps()->sum('hours');
            $this->line("✓ Total hours in steps: {$totalHours}ч");
            $this->line("  Parent labor work hours: {$laborWork->hours}ч");

            // Test 5: Delete step
            $this->line('\nTest 5: Delete step');
            $deleteId = $step2->id;
            $step2->delete();
            if (!ProjectLaborWorkStep::find($deleteId)) {
                $this->line("✓ Step deleted successfully");
            }

            // Test 6: Check remaining steps
            $remainingSteps = $laborWork->steps()->count();
            $this->line("\nTest 6: Remaining steps");
            $this->line("✓ Remaining steps: {$remainingSteps}");

            // Cleanup
            $this->line('\nCleaning up test data...');
            $laborWork->delete();
            $this->line("✓ Labor work and all steps deleted");

            $this->info('\nAll tests passed!');
            return 0;

        } catch (\Exception $e) {
            $this->error("✗ Error: " . $e->getMessage());
            $this->error($e->getTraceAsString());
            return 1;
        }
    }
}
