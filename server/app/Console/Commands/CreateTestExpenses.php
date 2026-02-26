<?php
namespace App\Console\Commands;

use App\Models\Expense;
use Illuminate\Console\Command;

class CreateTestExpenses extends Command
{
    protected $signature = 'create:test-expenses';
    protected $description = 'Create test expenses for project 1';

    public function handle()
    {
        // Delete existing
        Expense::where('project_id', 1)->delete();
        
        // Create new
        Expense::create([
            'project_id' => 1,
            'name' => 'Доставка материалов',
            'amount' => 5000,
            'description' => 'Доставка на объект'
        ]);
        
        Expense::create([
            'project_id' => 1,
            'name' => 'Утилизация отходов',
            'amount' => 2000,
            'description' => 'Вывоз обрезков и упаковки'
        ]);
        
        $this->info('✓ Created 2 test expenses');
    }
}
