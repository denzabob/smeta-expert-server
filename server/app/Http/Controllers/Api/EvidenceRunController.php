<?php

namespace App\Http\Controllers\Api;

use App\Evidence\CaptureMethod;
use App\Evidence\CostComponent;
use App\Evidence\EvidenceItemStatus;
use App\Evidence\EvidenceRunStatus;
use App\Evidence\EvidenceFeatures;
use App\Evidence\SourceType;
use App\Evidence\VerificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEvidenceRecordRequest;
use App\Http\Requests\StoreEvidenceRunRequest;
use App\Models\EstimateEvidenceItem;
use App\Models\EstimateEvidenceRun;
use App\Models\EvidenceRecord;
use App\Models\GenericEvidenceAsset;
use App\Models\Project;
use App\Services\EvidenceRunFinalizer;
use App\Services\EvidenceRunItemCollector;
use App\Services\EstimateEvidencePdfBuilder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EvidenceRunController extends Controller
{
    public function __construct(
        private EvidenceRunItemCollector $itemCollector,
        private EvidenceRunFinalizer $finalizer,
        private EstimateEvidencePdfBuilder $pdfBuilder,
    ) {}

    /**
     * GET /api/projects/{project}/evidence-runs
     */
    public function index(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $runs = EstimateEvidenceRun::where('project_id', $project->id)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $runs,
        ]);
    }

    /**
     * POST /api/projects/{project}/evidence-runs
     */
    public function store(StoreEvidenceRunRequest $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $run = EstimateEvidenceRun::create([
            'uuid'          => (string) Str::uuid(),
            'project_id'    => $project->id,
            'initiated_by'  => auth()->id(),
            'status'        => EvidenceRunStatus::PENDING,
            'total_items'   => 0,
            'completed_items' => 0,
            'failed_items'  => 0,
            'metadata_json' => $request->input('metadata'),
        ]);

        $run = $this->itemCollector->populateRun($run, $project, auth()->id());

        return response()->json([
            'success' => true,
            'data'    => $run->load('items'),
        ], 201);
    }

    /**
     * GET /api/projects/{project}/evidence-runs/{runId}
     */
    public function show(Project $project, int $runId): JsonResponse
    {
        $this->authorize('view', $project);

        $run = EstimateEvidenceRun::where('project_id', $project->id)
            ->with('items.evidenceRecord')
            ->findOrFail($runId);

        return response()->json([
            'success' => true,
            'data'    => $run,
        ]);
    }

    /**
     * POST /api/projects/{project}/evidence-runs/{runId}/finalize
     */
    public function finalize(Project $project, int $runId): JsonResponse
    {
        $this->authorize('update', $project);

        $run = EstimateEvidenceRun::where('project_id', $project->id)
            ->findOrFail($runId);

        $check = $this->finalizer->canFinalize($run);
        if (!$check['ok']) {
            return response()->json([
                'success' => false,
                'message' => $check['reason'],
            ], 422);
        }

        $run = $this->finalizer->finalize($run);

        return response()->json([
            'success' => true,
            'data'    => $run->load('items'),
        ]);
    }

    /**
     * POST /api/projects/{project}/evidence-runs/{runId}/items/{itemId}/resolve
     */
    public function resolveItem(Request $request, Project $project, int $runId, int $itemId): JsonResponse
    {
        $this->authorize('update', $project);

        $run = EstimateEvidenceRun::where('project_id', $project->id)
            ->findOrFail($runId);

        if (in_array($run->status, EvidenceRunStatus::terminalStatuses(), true)) {
            return response()->json([
                'success' => false,
                'message' => "Cannot modify items in a {$run->status} run.",
            ], 422);
        }

        $item = $run->items()->findOrFail($itemId);

        if (in_array($item->status, EvidenceItemStatus::terminalStatuses(), true)) {
            return response()->json([
                'success' => false,
                'message' => "Item already in terminal status '{$item->status}'.",
            ], 422);
        }

        $request->validate([
            'evidence_record_id' => 'required|integer|exists:evidence_records,id',
            'resolution_type'    => 'nullable|string|in:manual,auto',
        ]);

        $record = EvidenceRecord::findOrFail($request->input('evidence_record_id'));

        $item->update([
            'status'             => EvidenceItemStatus::RESOLVED,
            'resolution_type'    => $request->input('resolution_type', 'manual'),
            'evidence_record_id' => $record->id,
            'source_url'         => $item->source_url ?: $record->source_url,
            'effective_value'    => $record->observed_price ?? $item->effective_value,
            'currency'           => $record->currency ?? $item->currency,
        ]);

        $this->refreshRunCounters($run);

        return response()->json([
            'success' => true,
            'data'    => $item->fresh()->load('evidenceRecord'),
        ]);
    }

    /**
     * POST /api/projects/{project}/evidence-runs/{runId}/items/{itemId}/skip
     */
    public function skipItem(Request $request, Project $project, int $runId, int $itemId): JsonResponse
    {
        $this->authorize('update', $project);

        $run = EstimateEvidenceRun::where('project_id', $project->id)
            ->findOrFail($runId);

        if (in_array($run->status, EvidenceRunStatus::terminalStatuses(), true)) {
            return response()->json([
                'success' => false,
                'message' => "Cannot modify items in a {$run->status} run.",
            ], 422);
        }

        $item = $run->items()->findOrFail($itemId);

        if (in_array($item->status, EvidenceItemStatus::terminalStatuses(), true)) {
            return response()->json([
                'success' => false,
                'message' => "Item already in terminal status '{$item->status}'.",
            ], 422);
        }

        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $item->update([
            'status'          => EvidenceItemStatus::SKIPPED,
            'resolution_type' => 'skipped',
            'diagnostics_json' => $request->input('reason')
                ? ['skip_reason' => $request->input('reason')]
                : $item->diagnostics_json,
        ]);

        $this->refreshRunCounters($run);

        return response()->json([
            'success' => true,
            'data'    => $item->fresh(),
        ]);
    }

    /**
     * POST /api/evidence-records
     */
    public function createRecord(StoreEvidenceRecordRequest $request): JsonResponse
    {
        $record = EvidenceRecord::create([
            'uuid'                => (string) Str::uuid(),
            'cost_component'      => $request->input('cost_component'),
            'source_type'         => $request->input('source_type'),
            'capture_method'      => $request->input('capture_method'),
            'verification_status' => $request->input('verification_status', 'pending'),
            'source_url'          => $request->input('source_url'),
            'source_domain'       => $request->input('source_domain'),
            'observed_price'      => $request->input('observed_price'),
            'currency'            => $request->input('currency'),
            'observed_at'         => $request->input('observed_at'),
            'extracted_name'      => $request->input('extracted_name'),
            'extracted_article'   => $request->input('extracted_article'),
            'metadata_json'       => $request->input('metadata'),
            'confidence_score'    => $request->input('confidence_score'),
            'trust_score'         => $request->input('trust_score'),
            'created_by'          => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'data'    => $record,
        ], 201);
    }

    /**
     * POST /api/evidence-records/{id}/assets
     */
    public function uploadAsset(Request $request, int $id): JsonResponse
    {
        $record = EvidenceRecord::findOrFail($id);

        if ($record->created_by !== auth()->id()) {
            abort(403, 'Access denied.');
        }

        $request->validate([
            'file'       => 'required|file|max:10240',
            'asset_type' => 'nullable|string|max:50',
        ]);

        $file = $request->file('file');
        $path = $file->store('evidence-records/' . $record->uuid, 'public');

        $asset = GenericEvidenceAsset::create([
            'uuid'              => (string) Str::uuid(),
            'evidence_record_id' => $record->id,
            'asset_type'        => $request->input('asset_type', 'screenshot'),
            'file_path'         => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type'         => $file->getMimeType(),
            'file_size'         => $file->getSize(),
            'sha256'            => hash_file('sha256', $file->getRealPath()),
        ]);

        return response()->json([
            'success' => true,
            'data'    => $asset,
        ], 201);
    }

    /**
     * GET /api/evidence-records/search
     * Searchable list of evidence records for the picker UI.
     */
    public function searchRecords(Request $request): JsonResponse
    {
        $request->validate([
            'q'              => 'nullable|string|max:200',
            'cost_component' => 'nullable|string|in:' . implode(',', CostComponent::all()),
            'per_page'       => 'nullable|integer|min:1|max:50',
        ]);

        $userId = auth()->id();
        $query  = EvidenceRecord::where('created_by', $userId)
            ->orderByDesc('created_at');

        if ($cc = $request->input('cost_component')) {
            $query->where('cost_component', $cc);
        }

        if ($q = $request->input('q')) {
            $query->where(function ($sub) use ($q) {
                $sub->where('extracted_name', 'LIKE', "%{$q}%")
                    ->orWhere('source_url', 'LIKE', "%{$q}%")
                    ->orWhere('source_domain', 'LIKE', "%{$q}%")
                    ->orWhere('extracted_article', 'LIKE', "%{$q}%");
            });
        }

        $perPage = (int) $request->input('per_page', 20);
        $records = $query->with(['assets' => function ($q) {
            $q->select('id', 'evidence_record_id', 'asset_type', 'file_path')
              ->limit(1);
        }])->paginate($perPage);

        // Shape output: add has_screenshot flag, hide internal fields
        $items = collect($records->items())->map(function (EvidenceRecord $r) {
            return [
                'id'              => $r->id,
                'uuid'            => $r->uuid,
                'extracted_name'  => $r->extracted_name,
                'source_url'      => $r->source_url,
                'source_domain'   => $r->source_domain,
                'observed_price'  => $r->observed_price,
                'currency'        => $r->currency,
                'cost_component'  => $r->cost_component,
                'capture_method'  => $r->capture_method,
                'observed_at'     => $r->observed_at?->toIso8601String(),
                'created_at'      => $r->created_at?->toIso8601String(),
                'has_screenshot'  => $r->assets->isNotEmpty(),
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => $items,
            'meta'    => [
                'current_page' => $records->currentPage(),
                'last_page'    => $records->lastPage(),
                'per_page'     => $records->perPage(),
                'total'        => $records->total(),
            ],
        ]);
    }

    /**
     * POST /api/projects/{project}/evidence-runs/{runId}/items/{itemId}/manual-resolve
     * Manual fallback: create evidence record from uploaded proof + resolve item in one step.
     */
    public function manualResolveItem(Request $request, Project $project, int $runId, int $itemId): JsonResponse
    {
        $this->authorize('update', $project);

        $run = EstimateEvidenceRun::where('project_id', $project->id)
            ->findOrFail($runId);

        if (in_array($run->status, EvidenceRunStatus::terminalStatuses(), true)) {
            return response()->json([
                'success' => false,
                'message' => "Cannot modify items in a {$run->status} run.",
            ], 422);
        }

        $item = $run->items()->findOrFail($itemId);

        if (in_array($item->status, EvidenceItemStatus::terminalStatuses(), true)) {
            return response()->json([
                'success' => false,
                'message' => "Item already in terminal status '{$item->status}'.",
            ], 422);
        }

        $request->validate([
            'file'           => 'required|file|max:10240',
            'observed_price' => 'required|numeric|min:0',
            'currency'       => 'nullable|string|max:10',
            'source_url'     => 'nullable|url|max:2048',
            'extracted_name' => 'nullable|string|max:500',
        ]);

        return DB::transaction(function () use ($request, $item, $run) {
            $file = $request->file('file');

            // 1. Create EvidenceRecord
            $record = EvidenceRecord::create([
                'uuid'                => (string) Str::uuid(),
                'cost_component'      => $item->cost_component,
                'source_type'         => SourceType::MANUAL_INPUT,
                'capture_method'      => CaptureMethod::FILE_UPLOAD,
                'verification_status' => VerificationStatus::PENDING,
                'source_url'          => $request->input('source_url'),
                'source_domain'       => $request->input('source_url')
                    ? (parse_url($request->input('source_url'), PHP_URL_HOST) ?: null)
                    : null,
                'observed_price'      => $request->input('observed_price'),
                'currency'            => strtoupper($request->input('currency', 'RUB')),
                'observed_at'         => now(),
                'extracted_name'      => $request->input('extracted_name'),
                'created_by'          => auth()->id(),
            ]);

            // 2. Store uploaded file as asset
            $path = $file->store('evidence-records/' . $record->uuid, 'public');
            GenericEvidenceAsset::create([
                'uuid'               => (string) Str::uuid(),
                'evidence_record_id' => $record->id,
                'asset_type'         => 'document',
                'file_path'          => $path,
                'original_filename'  => $file->getClientOriginalName(),
                'mime_type'          => $file->getMimeType(),
                'file_size'          => $file->getSize(),
                'sha256'             => hash_file('sha256', $file->getRealPath()),
            ]);

            // 3. Resolve item
            $item->update([
                'status'             => EvidenceItemStatus::RESOLVED,
                'resolution_type'    => 'manual',
                'evidence_record_id' => $record->id,
                'source_url'         => $item->source_url ?: $record->source_url,
                'effective_value'    => $record->observed_price,
                'currency'           => $record->currency,
            ]);

            $this->refreshRunCounters($run);

            return response()->json([
                'success' => true,
                'data'    => $item->fresh()->load('evidenceRecord'),
            ], 201);
        });
    }

    /**
     * Recalculate run counters and transition status to READY when all items are terminal.
     */
    private function refreshRunCounters(EstimateEvidenceRun $run): void
    {
        $items = $run->items()->get();

        $completed = $items->filter(
            fn($i) => in_array($i->status, EvidenceItemStatus::completedStatuses(), true)
        )->count();

        $failed = $items->where('status', EvidenceItemStatus::FAILED)->count();

        $allTerminal = $items->every(
            fn($i) => in_array($i->status, EvidenceItemStatus::terminalStatuses(), true)
        );

        $updates = [
            'total_items'     => $items->count(),
            'completed_items' => $completed,
            'failed_items'    => $failed,
        ];

        if ($allTerminal && $items->isNotEmpty() && $run->status === EvidenceRunStatus::IN_PROGRESS) {
            $updates['status'] = EvidenceRunStatus::READY;
        }

        $run->update($updates);
    }

    /**
     * GET /api/projects/{project}/evidence-runs/{runId}/pdf
     * Generate a unified evidence PDF for a finalized generic evidence run.
     */
    public function pdf(Project $project, int $runId)
    {
        if (!EvidenceFeatures::genericChromeEnabled()) {
            abort(404);
        }

        $this->authorize('view', $project);

        $run = EstimateEvidenceRun::where('project_id', $project->id)
            ->findOrFail($runId);

        if ($run->status !== EvidenceRunStatus::FINALIZED) {
            return response()->json([
                'success' => false,
                'message' => 'Evidence run must be finalized before generating a PDF.',
            ], 422);
        }

        if (empty($run->snapshot_json)) {
            return response()->json([
                'success' => false,
                'message' => 'Evidence run has no snapshot data.',
            ], 422);
        }

        $viewData = $this->pdfBuilder->build($run, $project);

        $pdf = Pdf::loadView('reports.evidence_run', $viewData)
            ->setPaper('a4')
            ->setOption('isHtml5ParserEnabled', true)
            ->setOption('isPhpEnabled', false)
            ->setOption('defaultFont', 'DejaVu Sans')
            ->setOption('fontDir', config('dompdf.font_dir'))
            ->setOption('fontCache', config('dompdf.font_cache_dir'));

        $rawFilename = "evidence_{$project->number}_run_{$run->id}.pdf";
        $filename = preg_replace('#[\\/:*?"<>|]#', '_', $rawFilename);

        return $pdf->download($filename);
    }
}
