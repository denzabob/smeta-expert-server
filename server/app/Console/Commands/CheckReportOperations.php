<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Project;
use App\Service\ReportService;
use App\Services\Smeta\SmetaCalculator;

class CheckReportOperations extends Command
{
    protected $signature = 'check:report-operations {project_id=1}';
    protected $description = 'Check operations in generated report';

    public function handle()
    {
        $projectId = $this->argument('project_id');
        
        $project = Project::find($projectId);
        if (!$project) {
            $this->error("Проект с ID {$projectId} не найден");
            return 1;
        }
        
        $this->info("=== Проверка операций в отчёте для проекта: {$project->number} ===\n");
        
        $service = new ReportService(new SmetaCalculator());
        $report = $service->buildReport($project);
        
        $this->info("Операции в отчёте:");
        $this->line(str_repeat("-", 100));
        $this->line(sprintf(
            "%-40s | %-25s | %10s %4s | %12s | %12s",
            "Название",
            "Категория",
            "Количество",
            "Ед.",
            "Цена/ед.",
            "Сумма"
        ));
        $this->line(str_repeat("-", 100));
        
        foreach ($report->operations as $op) {
            $this->line(sprintf(
                "%-40s | %-25s | %10.2f %4s | %12.2f | %12.2f",
                substr($op->name, 0, 40),
                substr($op->category, 0, 25),
                $op->quantity,
                $op->unit,
                $op->cost_per_unit,
                $op->total_cost
            ));
        }
        
        $this->line(str_repeat("-", 100));
        
        $this->info("\nИтоговая стоимость операций: " . number_format($report->totals->operations_cost, 2, ',', ' ') . " руб");
        
        // Проверка наличия ключевых операций
        $this->info("\nПроверка ключевых операций:");
        
        $hasCutting = false;
        $hasEdging = false;
        
        foreach ($report->operations as $op) {
            if (strpos($op->category, 'Обработка плитных') !== false || strpos($op->name, 'Распиловка') !== false) {
                $hasCutting = true;
            }
            if (strpos($op->category, 'Кромкооблицовка') !== false || strpos($op->name, 'Кромкооблицовка') !== false) {
                $hasEdging = true;
            }
        }
        
        if ($hasCutting) {
            $this->line("  ✓ Раскрой ДСП: присутствует в отчёте");
        } else {
            $this->warn("  ✗ Раскрой ДСП: отсутствует в отчёте");
        }
        
        if ($hasEdging) {
            $this->line("  ✓ Кромкооблицовка деталей: присутствует в отчёте");
        } else {
            $this->warn("  ✗ Кромкооблицовка: отсутствует в отчёте");
        }
        
        $this->info("\nОтчёт успешно проверен!");
        return 0;
    }
}
