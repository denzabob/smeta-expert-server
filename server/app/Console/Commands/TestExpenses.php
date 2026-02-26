<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\Expense;
use Illuminate\Console\Command;

class TestExpenses extends Command
{
    protected $signature = 'test:expenses {projectId=4}';
    protected $description = 'Test creating and updating expenses';

    public function handle()
    {
        $projectId = $this->argument('projectId');
        $project = Project::find($projectId);

        if (!$project) {
            $this->error("Project $projectId not found");
            return 1;
        }

        $this->info("Testing expenses for project: {$project->code}");

        // Test 1: Create expense
        $this->line('');
        $this->info('Test 1: Create expense');
        try {
            $expense = Expense::create([
                'project_id' => $projectId,
                'name' => 'Доставка материалов',
                'amount' => 5000.00,
                'description' => 'Доставка по городу Екатеринбург'
            ]);
            $this->line("✓ Expense created: ID {$expense->id}");
            $this->line("  - Name: {$expense->name}");
            $this->line("  - Amount: {$expense->amount}");
            $this->line("  - Description: {$expense->description}");

            // Test 2: Update expense
            $this->line('');
            $this->info('Test 2: Update expense');
            $expense->update([
                'name' => 'Доставка + монтаж',
                'amount' => 7500.00,
                'description' => 'Доставка и базовый монтаж'
            ]);
            $expense->refresh();
            $this->line("✓ Expense updated");
            $this->line("  - Name: {$expense->name}");
            $this->line("  - Amount: {$expense->amount}");
            $this->line("  - Description: {$expense->description}");

            // Test 3: Delete expense
            $this->line('');
            $this->info('Test 3: Delete expense');
            $expenseId = $expense->id;
            $expense->delete();
            $this->line("✓ Expense deleted: ID $expenseId");

        } catch (\Exception $e) {
            $this->error("✗ Error: " . $e->getMessage());
            return 1;
        }

        $this->info('All tests passed!');
        return 0;
    }
}
