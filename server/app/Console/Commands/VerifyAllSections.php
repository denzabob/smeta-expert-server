<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Service\ReportService;
use Illuminate\Console\Command;

class VerifyAllSections extends Command
{
    protected $signature = 'verify:all-sections {project_id=1}';
    protected $description = 'Verify all sections are populated in report';

    public function __construct(
        private ReportService $reportService
    ) {
        parent::__construct();
    }

    public function handle()
    {
        $projectId = $this->argument('project_id');
        $project = Project::findOrFail($projectId);

        $report = $this->reportService->buildReport($project);
        $arr = $report->toArray();

        $this->info("=== Проверка всех секций отчёта: {$project->number} ===\n");

        $sections = [
            'plates' => ['name' => 'Расчёт плитных материалов', 'total' => 'plates_cost'],
            'edges' => ['name' => 'Расчёт кромочного материала', 'total' => 'edges_cost'],
            'operations' => ['name' => 'Расчёт стоимости работ', 'total' => 'operations_cost'],
            'fittings' => ['name' => 'Фурнитура и комплектующие', 'total' => 'fittings_cost'],
            'expenses' => ['name' => 'Накладные расходы', 'total' => 'expenses_cost'],
            'labor_works' => ['name' => 'Нормируемые работы', 'total' => 'labor_works_cost'],
        ];

        foreach ($sections as $key => $section) {
            $count = count($arr[$key] ?? []);
            $total = $arr['totals'][$section['total']] ?? 0;
            
            if ($count > 0) {
                $status = "✓";
                $msg = "{$status} {$section['name']}: {$count} позиций → {$total} ₽";
            } else {
                $status = "○";
                $msg = "{$status} {$section['name']}: нет данных";
            }
            
            $this->line($msg);
        }

        $this->line("");
        $this->info("✓ Итого по проекту: " . ($arr['totals']['total'] ?? 0) . " ₽");
    }
}
