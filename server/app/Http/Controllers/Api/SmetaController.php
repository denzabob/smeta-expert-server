<?php
// app/Http/Controllers/Api/SmetaController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Service\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Barryvdh\DomPDF\Facade\Pdf;

class SmetaController extends Controller
{
    public function __construct(private ReportService $reportService) {}

    /**
     * Получить полный отчёт по проекту в формате JSON
     */
    public function report($projectId)
    {
        $project = Project::findOrFail($projectId);
        
        // Проверка авторизации: пользователь может видеть только свои проекты
        $this->authorize('view', $project);

        $reportDto = $this->reportService->buildReport($project);
        
        return response()->json($reportDto->toArray());
    }

    public function calculate(Request $request)
    {
        $project = Project::findOrFail($request->project_id);
        $total = 0;  // Реализуй расчёт по формулам из ТЗ
        // ... логика расчёта

        return response()->json(['total' => $total, 'details' => []]);
    }

    public function generatePdf($projectId)
    {
        $project = Project::findOrFail($projectId);
        $reportDto = $this->reportService->buildReport($project);
        $report = $reportDto->toArray();
        
        $pdf = Pdf::loadView('smeta.report', compact('report'))
            ->setPaper('a4', 'portrait')
            ->setOption('isHtml5ParserEnabled', true)
            ->setOption('isRemoteEnabled', true)
            ->setOption('defaultFont', 'DejaVu Sans');
        
        return $pdf->download('smeta.pdf');
    }
}
