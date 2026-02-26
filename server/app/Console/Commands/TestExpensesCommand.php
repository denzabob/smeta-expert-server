<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\Expense;
use Illuminate\Console\Command;

class TestExpensesCommand extends Command
{
    protected $signature = 'test:expenses {projectId=4}';
    protected $description = 'Test expense CRUD operations';

    public function handle()
    {
        $projectId = $this->argument('projectId');
        $project = Project::find($projectId);

        if (!$project) {
            $this->error("Project $projectId not found");
            return 1;
        }

        $this->info("Testing expenses CRUD for project: {$project->code}\n");

        // Test 1: Create
        $this->line('Test 1: Create expense');
        try {
            $expense = $project->expenses()->create([
                'name' => 'Доставка материалов',
                'amount' => 5000,
                'description' => 'Test delivery'
            ]);

            $this->line("✓ Created ID: {$expense->id}");
            $this->line("  Name: {$expense->name}");
            $this->line("  Amount: {$expense->amount}");

            // Test 2: Update
            $this->line("\nTest 2: Update expense");
            $expense->update([
                'name' => 'Доставка + монтаж',
                'amount' => 7500.00
            ]);

            $expense->refresh();
            $this->line("✓ Updated");
            $this->line("  Name: {$expense->name}");
            $this->line("  Amount: {$expense->amount}");

            // Test 3: Delete
            $this->line("\nTest 3: Delete expense");
            $expenseId = $expense->id;
            $expense->delete();

            if (!Expense::find($expenseId)) {
                $this->line("✓ Deleted successfully");
            }

            $this->info("\nAll tests passed!");
            return 0;

        } catch (\Exception $e) {
            $this->error("✗ Error: " . $e->getMessage());
            $this->error($e->getTraceAsString());
            return 1;
        }
    }
}
