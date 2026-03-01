<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Service\ReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\RevisionPublication;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

class SmetaPdfController extends Controller
{
    public function __construct(
        private ReportService $reportService
    ) {}

    /**
     * Generate and download PDF report for a project
     *
     * GET /api/smeta/pdf/{projectId}
     */
    public function generate(Project $project)
    {
        // Authorize access
        $this->authorize('view', $project);

        try {
            // Get report using existing ReportService (no duplication!)
            $report = $this->reportService->buildReport($project);
            $reportArray = $report->toArray();

            // Generate PDF from Blade template using DomPDF with UTF-8 support
            $publication = RevisionPublication::whereHas('revision', function ($q) use ($project) {
                $q->where('project_id', $project->id)->where('status', 'published');
            })->where('is_active', true)->orderByDesc('created_at')->first();

            $publicUrl = $publication ? $this->makePublicVerificationUrl($publication->public_id) : null;
            $qrSvg = $publicUrl ? $this->makeQrSvg($publicUrl) : null;

            $pdf = Pdf::loadView('reports.smeta', [
                'report' => $reportArray,
                'qrSvg' => $qrSvg,
                'revisionNumber' => $publication?->revision?->number,
                'revisionDate' => $publication?->revision?->created_at?->format('d.m.Y') ?? date('d.m.Y'),
                'snapshotHashShort' => $publication?->revision?->snapshot_hash
                    ? (substr($publication->revision->snapshot_hash, 0, 8) . '…' . substr($publication->revision->snapshot_hash, -8))
                    : null,
                'engineVersion' => $publication?->revision?->calculation_engine_version,
                'documentToken' => $publication?->public_id,
            ])
            ->setPaper('a4')
            ->setOption('isHtml5ParserEnabled', true)
            ->setOption('isPhpEnabled', false)
            ->setOption('defaultFont', 'DejaVu Sans')
            ->setOption('fontDir', config('dompdf.font_dir'))
            ->setOption('fontCache', config('dompdf.font_cache_dir'));

            // Return as download with sanitized filename
            $filename = $this->sanitizeFilename("smeta_{$project->number}_{$project->id}.pdf");
            return $pdf->download($filename);

        } catch (\Exception $e) {
            \Log::error('PDF generation error', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Ошибка при генерации PDF',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Sanitize filename by removing invalid characters
     */
    private function sanitizeFilename(string $filename): string
    {
        // Remove / \ : * ? " < > | characters
        return preg_replace('/[\/\\:*?"<>|]/', '_', $filename);
    }

    private function makeQrSvg(string $url): string
    {
        $options = new QROptions([
            'outputType' => QRCode::OUTPUT_MARKUP_SVG,
            'scale' => 4,
            'quietzoneSize' => 0,
            'outputBase64' => false,
        ]);

        $svg = (new QRCode($options))->render($url);
        if (!is_string($svg) || $svg === '') {
            return '';
        }

        if (str_starts_with($svg, 'data:image/svg+xml;base64,')) {
            return $svg;
        }

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    private function makePublicVerificationUrl(string $publicId): string
    {
        return rtrim((string) config('app.public_verify_base_url'), '/') . "/v/{$publicId}";
    }
}
