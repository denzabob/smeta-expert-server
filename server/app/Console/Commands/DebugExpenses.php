<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Service\ReportService;
use Illuminate\Console\Command;

class DebugExpenses extends Command
{
    protected $signature = 'debug:expenses {project_id=1}';
    protected $description = 'Debug expenses loading for a project';

    public function __construct(
        private ReportService $reportService
    ) {
        parent::__construct();
    }

    public function handle()
    {
        $projectId = $this->argument('project_id');
        $project = Project::findOrFail($projectId);

        $this->info("=== Project: {$project->id} ===");
        
        // Check raw database query
        $expenses = $project->expenses()->get();
        $this->info("✓ Raw expenses count: {$expenses->count()}");
        
        foreach ($expenses as $exp) {
            $this->line("  - ID: {$exp->id}, Type: '{$exp->type}', Cost: {$exp->cost}, Desc: '{$exp->description}'");
        }

        // Check through calculator
        $calculator = app(\App\Services\Smeta\SmetaCalculator::class);
        $expenseDtos = $calculator->calculateExpensesData($project);
        $this->info("✓ Calculator expenses: " . count($expenseDtos));
        
        foreach ($expenseDtos as $dto) {
            $this->line("  - Type: '{$dto->type}', Cost: {$dto->cost}");
        }

        // Check through service
        $report = $this->reportService->buildReport($project);
        $this->info("✓ ReportDto expenses: " . count($report->expenses));
        
        foreach ($report->expenses as $dto) {
            $this->line("  - Type: '{$dto->type}', Cost: {$dto->cost}");
        }

        // Check in array form
        $arr = $report->toArray();
        $this->info("✓ toArray() expenses: " . count($arr['expenses']));
        
        foreach ($arr['expenses'] as $exp) {
            $this->line("  - Type: '{$exp['type']}', Cost: {$exp['cost']}");
        }

        // Check if empty
        $isEmpty = empty($arr['expenses']);
        $this->info("✓ Is empty: " . ($isEmpty ? 'YES' : 'NO'));
    }
}
