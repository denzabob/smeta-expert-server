<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Project;
use App\Service\ReportService;
use App\Services\Smeta\SmetaCalculator;
use Barryvdh\DomPDF\Facade\Pdf;

class GenerateTestPdf extends Command
{
    protected $signature = 'generate:test-pdf {project_id=1}';
    protected $description = 'Generate test PDF report for verification';

    public function handle()
    {
        $projectId = $this->argument('project_id');
        
        $project = Project::find($projectId);
        if (!$project) {
            $this->error("Проект с ID {$projectId} не найден");
            return 1;
        }
        
        $this->info("=== Генерация PDF отчёта для проекта: {$project->number} ===\n");
        
        try {
            $service = new ReportService(new SmetaCalculator());
            $report = $service->buildReport($project);
            $reportArray = $report->toArray();
            
            $this->info("Проверка данных операций в отчёте:");
            $this->line(sprintf("  Количество операций: %d", count($reportArray['operations'])));
            
            foreach ($reportArray['operations'] as $op) {
                $this->line(sprintf(
                    "  - %s (%s): %.2f %s = %.2f руб",
                    $op['name'],
                    $op['category'],
                    $op['quantity'],
                    $op['unit'],
                    $op['total_cost']
                ));
            }
            
            // Generate PDF
            $this->info("\nГенерация PDF...");
            $pdf = Pdf::loadView('reports.smeta', [
                'report' => $reportArray
            ])
            ->setPaper('a4')
            ->setOption('isHtml5ParserEnabled', true)
            ->setOption('isPhpEnabled', false)
            ->setOption('defaultFont', 'DejaVu Sans')
            ->setOption('fontDir', config('dompdf.font_dir'))
            ->setOption('fontCache', config('dompdf.font_cache_dir'));
            
            $filename = storage_path("app/public/test_report_{$project->id}.pdf");
            $pdf->save($filename);
            
            $this->info("✓ PDF сохранён в: {$filename}");
            $this->info("Размер файла: " . filesize($filename) . " байт");
            
            return 0;
        } catch (\Exception $e) {
            $this->error("Ошибка при генерации PDF:");
            $this->error($e->getMessage());
            $this->error($e->getTraceAsString());
            return 1;
        }
    }
}
