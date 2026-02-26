<?php

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\Project;
use App\Service\ReportService;

$project = Project::find(5);
$reportService = app(ReportService::class);
$report = $reportService->buildReport($project);
$reportArray = $report->toArray();

$pdf = Pdf::loadView('reports.smeta', [
    'report' => $reportArray
])
->setPaper('a4')
->setOption('isHtml5ParserEnabled', true)
->setOption('isPhpEnabled', false)
->setOption('defaultFont', 'DejaVu Sans')
->setOption('fontDir', config('dompdf.font_dir'))
->setOption('fontCache', config('dompdf.font_cache_dir'));

file_put_contents('/tmp/smeta_fixed.pdf', $pdf->output());
echo "PDF saved to /tmp/smeta_fixed.pdf\n";
