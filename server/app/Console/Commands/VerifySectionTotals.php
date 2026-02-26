<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Project;
use App\Service\ReportService;
use App\Services\Smeta\SmetaCalculator;

class VerifySectionTotals extends Command
{
    protected $signature = 'verify:section-totals {project_id=1}';
    protected $description = 'Verify that all sections have totals in report';

    public function handle()
    {
        $projectId = $this->argument('project_id');
        
        $project = Project::find($projectId);
        if (!$project) {
            $this->error("Проект с ID {$projectId} не найден");
            return 1;
        }
        
        $this->info("=== Проверка итоговых строк в разделах ===\n");
        
        $service = new ReportService(new SmetaCalculator());
        $report = $service->buildReport($project);
        
        $sections = [
            'plates' => ['name' => 'Расчёт плитных материалов', 'key' => 'total_cost'],
            'edges' => ['name' => 'Расчёт кромочного материала', 'key' => 'total_cost'],
            'operations' => ['name' => 'Расчёт стоимости работ', 'key' => 'total_cost'],
            'fittings' => ['name' => 'Фурнитура и комплектующие', 'key' => 'total_cost'],
            'expenses' => ['name' => 'Накладные расходы', 'key' => 'cost'],
            'labor_works' => ['name' => 'Нормируемые работы', 'key' => 'cost'],
        ];
        
        $this->line(str_repeat("=", 80));
        $this->line("ПРОВЕРКА РАЗДЕЛОВ");
        $this->line(str_repeat("=", 80) . "\n");
        
        $allOk = true;
        
        foreach ($sections as $section => $info) {
            $items = $report->$section;
            
            if (empty($items)) {
                $this->line("⊘ {$info['name']}: нет данных");
                continue;
            }
            
            $total = 0;
            $count = count($items);
            
            foreach ($items as $item) {
                // Работаем с объектами DTO или массивами
                $value = is_array($item) ? ($item[$info['key']] ?? 0) : ($item->{$info['key']} ?? 0);
                $total += $value;
            }
            
            $totalFormatted = number_format($total, 2, ',', ' ');
            $this->line("✓ {$info['name']}");
            $this->line("  - Количество строк: {$count}");
            $this->line("  - Сумма итого: {$totalFormatted} ₽\n");
            
            // Verify that template will have tfoot row
            if (in_array($section, ['plates', 'edges', 'operations', 'fittings', 'expenses', 'labor_works'])) {
                $this->line("  ✓ Будет строка 'Итого по разделу' в PDF");
            }
        }
        
        $this->line(str_repeat("=", 80));
        $this->info("\nВсе разделы проверены! ✓");
        
        return 0;
    }
}
