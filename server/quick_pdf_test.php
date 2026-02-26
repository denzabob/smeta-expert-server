<?php

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\Project;
use App\Service\ReportService;

// Build report for project 5
$project = Project::find(5);
if (!$project) {
    echo "❌ Project 5 not found\n";
    exit(1);
}

$reportService = app(ReportService::class);
try {
    $report = $reportService->buildReport($project);
    $reportArray = $report->toArray();
    
    echo "✅ Report built successfully\n";
    echo "   - Edges: " . count($reportArray['edges'] ?? []) . "\n";
    
    // Generate PDF
    $pdf = Pdf::loadView('reports.smeta', [
        'report' => $reportArray
    ])
    ->setPaper('a4')
    ->setOption('isHtml5ParserEnabled', true)
    ->setOption('isPhpEnabled', false)
    ->setOption('defaultFont', 'DejaVu Sans')
    ->setOption('fontDir', config('dompdf.font_dir'))
    ->setOption('fontCache', config('dompdf.font_cache_dir'));
    
    $output = $pdf->output();
    echo "✅ PDF generated: " . strlen($output) . " bytes\n";
    
    // Check for detail names
    if (strpos($output, 'Неизвестно') !== false) {
        echo "❌ 'Неизвестно' found in PDF\n";
    } else {
        echo "✅ 'Неизвестно' NOT in PDF\n";
    }
    
    if (strpos($output, 'Кромка') !== false) {
        echo "✅ 'Кромка' found in PDF\n";
    } else {
        echo "❌ 'Кромка' NOT found in PDF\n";
    }
    
} catch (\Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
