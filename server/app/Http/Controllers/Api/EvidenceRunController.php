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
use App\Models\EvidenceLink;
use App\Models\EvidenceRecord;
use App\Models\GenericEvidenceAsset;
use App\Models\Project;
use App\Services\MaterialConfirmationService;
use App\Services\EvidenceRunFinalizer;
use App\Services\EvidenceRunItemCollector;
use App\Services\EstimateEvidencePdfBuilder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EvidenceRunController extends Controller
{
    public function __construct(
        private EvidenceRunItemCollector $itemCollector,
        private EvidenceRunFinalizer $finalizer,
        private EstimateEvidencePdfBuilder $pdfBuilder,
        private MaterialConfirmationService $confirmationService,
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
     * POST /api/projects/{project}/evidence-runs/{runId}/refresh
     *
     * Re-evaluate pending items against current fresh proof and return
     * the updated run.  Uses the same proof-selection rule as creation-time
     * auto-resolve (MaterialConfirmationService::getFreshRecord).
     */
    public function refresh(Project $project, int $runId): JsonResponse
    {
        $this->authorize('update', $project);

        $run = EstimateEvidenceRun::where('project_id', $project->id)
            ->findOrFail($runId);

        if (in_array($run->status, EvidenceRunStatus::terminalStatuses(), true)) {
            return response()->json([
                'success' => false,
                'message' => "Cannot refresh a {$run->status} run.",
            ], 422);
        }

        $resolved = $this->itemCollector->refreshPendingItems($run, $project);

        $this->refreshRunCounters($run);

        return response()->json([
            'success'       => true,
            'data'          => $run->fresh()->load('items.evidenceRecord'),
            'auto_resolved' => $resolved,
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

        // Strict validation: record must be a valid candidate for this item
        if (!$this->confirmationService->isValidCandidateForItem(
            $record,
            $item->cost_component,
            $item->source_url,
        )) {
            return response()->json([
                'success' => false,
                'message' => 'Selected evidence record does not match this item (component/URL mismatch or no proof asset).',
            ], 422);
        }

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
     * Retrieve a single EvidenceRecord with full detail.
     *
     * GET /api/evidence-records/{record}
     *
     * Authorization: the calling user must own at least one target linked to this record.
     * Ownership is verified through the supplier chain used in H4–H8:
     *   operation_price   → price_list_versions → price_lists → suppliers.user_id
     *   price_list_version → price_lists → suppliers.user_id
     */
    public function showRecord(Request $request, EvidenceRecord $record): JsonResponse
    {
        $userId = $request->user()->id;

        $links = EvidenceLink::where('evidence_record_id', $record->id)->get();

        $authorized = $links->contains(function (EvidenceLink $link) use ($userId) {
            if ($link->linkable_type === 'operation_price') {
                return DB::table('operation_prices as op')
                    ->join('price_list_versions as plv', 'plv.id', '=', 'op.price_list_version_id')
                    ->join('price_lists as pl', 'pl.id', '=', 'plv.price_list_id')
                    ->join('suppliers as s', 's.id', '=', 'pl.supplier_id')
                    ->where('op.id', $link->linkable_id)
                    ->where('s.user_id', $userId)
                    ->exists();
            }

            if ($link->linkable_type === 'price_list_version') {
                return DB::table('price_list_versions as plv')
                    ->join('price_lists as pl', 'pl.id', '=', 'plv.price_list_id')
                    ->join('suppliers as s', 's.id', '=', 'pl.supplier_id')
                    ->where('plv.id', $link->linkable_id)
                    ->where('s.user_id', $userId)
                    ->exists();
            }

            return false;
        });

        if (!$authorized) {
            abort(403, 'Доступ запрещен');
        }

        $record->load(['assets', 'links']);

        $assets = $record->assets->map(fn (GenericEvidenceAsset $asset) => [
            'asset_id'          => $asset->id,
            'asset_type'        => $asset->asset_type,
            'original_filename' => $asset->original_filename,
            'mime_type'         => $asset->mime_type,
            'file_size'         => $asset->file_size,
            'download_url'      => $asset->file_path
                ? Storage::disk('public')->url($asset->file_path)
                : null,
        ])->values()->all();

        $linkedTargets = $record->links->map(fn (EvidenceLink $link) => [
            'type' => $link->linkable_type,
            'id'   => $link->linkable_id,
        ])->values()->all();

        return response()->json([
            'data' => [
                'evidence_record_id'  => $record->id,
                'uuid'                => $record->uuid,
                'observed_price'      => $record->observed_price,
                'currency'            => $record->currency,
                'cost_component'      => $record->cost_component,
                'source_type'         => $record->source_type,
                'capture_method'      => $record->capture_method,
                'source_url'          => $record->source_url,
                'verification_status' => $record->verification_status,
                'metadata_json'       => $record->metadata_json,
                'created_by'          => $record->created_by,
                'created_at'          => $record->created_at?->toIso8601String(),
                'linked_targets'      => $linkedTargets,
                'assets'              => $assets,
            ],
        ]);
    }

    /**
     * Allowed verification_status transitions (H12).
     * States absent from keys are terminal — no outgoing transitions permitted.
     */
    private const ALLOWED_TRANSITIONS = [
        'pending'       => ['manual_verified', 'rejected'],
        'stale'         => ['manual_verified', 'rejected'],
        'auto_verified' => ['manual_verified', 'rejected'],
    ];

    /**
     * PATCH /api/evidence-records/{record}/verification-status
     *
     * Authorization: same linked-target ownership check as showRecord().
     * Updates only verification_status; all other fields remain unchanged.
     * Transition rules: only explicitly defined source→target pairs are accepted.
     */
    public function updateVerificationStatus(Request $request, EvidenceRecord $record): JsonResponse
    {
        $userId = $request->user()->id;

        $links = EvidenceLink::where('evidence_record_id', $record->id)->get();

        $authorized = $links->contains(function (EvidenceLink $link) use ($userId) {
            if ($link->linkable_type === 'operation_price') {
                return DB::table('operation_prices as op')
                    ->join('price_list_versions as plv', 'plv.id', '=', 'op.price_list_version_id')
                    ->join('price_lists as pl', 'pl.id', '=', 'plv.price_list_id')
                    ->join('suppliers as s', 's.id', '=', 'pl.supplier_id')
                    ->where('op.id', $link->linkable_id)
                    ->where('s.user_id', $userId)
                    ->exists();
            }

            if ($link->linkable_type === 'price_list_version') {
                return DB::table('price_list_versions as plv')
                    ->join('price_lists as pl', 'pl.id', '=', 'plv.price_list_id')
                    ->join('suppliers as s', 's.id', '=', 'pl.supplier_id')
                    ->where('plv.id', $link->linkable_id)
                    ->where('s.user_id', $userId)
                    ->exists();
            }

            return false;
        });

        if (!$authorized) {
            abort(403, 'Доступ запрещен');
        }

        $validated = $request->validate([
            'verification_status' => [
                'required',
                'string',
                'in:' . implode(',', VerificationStatus::all()),
            ],
        ]);

        $current   = $record->verification_status;
        $requested = $validated['verification_status'];
        $permitted = self::ALLOWED_TRANSITIONS[$current] ?? [];

        if (!in_array($requested, $permitted, true)) {
            throw ValidationException::withMessages([
                'verification_status' =>
                    "Cannot transition from '{$current}' to '{$requested}'.",
            ]);
        }

        $record->update(['verification_status' => $requested]);

        return response()->json([
            'data' => [
                'evidence_record_id'  => $record->id,
                'verification_status' => $record->verification_status,
                'updated_at'          => $record->updated_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * GET /api/evidence-records
     *
     * Paginated list of EvidenceRecords accessible to the user via linked-target ownership.
     * Auth scoping uses correlated EXISTS subqueries (no N+1).
     * Optional filters: linkable_type, linkable_id, verification_status, source_type, has_assets.
     */
    public function listRecords(Request $request): JsonResponse
    {
        $request->validate([
            'linkable_type'       => 'nullable|string|in:operation_price,price_list_version,operation',
            'linkable_id'         => 'nullable|integer|min:1',
            'verification_status' => 'nullable|string|in:' . implode(',', VerificationStatus::all()),
            'source_type'         => 'nullable|string|in:' . implode(',', SourceType::all()),
            'has_assets'          => 'nullable|boolean',
            'per_page'            => 'nullable|integer|min:1|max:100',
        ]);

        $userId = $request->user()->id;

        $query = EvidenceRecord::query()
            ->where(function ($q) use ($userId) {
                // Records accessible via an operation_price link owned by the user
                $q->whereExists(function ($sub) use ($userId) {
                    $sub->select(DB::raw(1))
                        ->from('evidence_links as el')
                        ->join('operation_prices as op', function ($j) {
                            $j->on('op.id', '=', 'el.linkable_id')
                              ->where('el.linkable_type', 'operation_price');
                        })
                        ->join('price_list_versions as plv', 'plv.id', '=', 'op.price_list_version_id')
                        ->join('price_lists as pl', 'pl.id', '=', 'plv.price_list_id')
                        ->join('suppliers as s', 's.id', '=', 'pl.supplier_id')
                        ->whereColumn('el.evidence_record_id', 'evidence_records.id')
                        ->where('s.user_id', $userId);
                })
                // Records accessible via a price_list_version link owned by the user
                ->orWhereExists(function ($sub) use ($userId) {
                    $sub->select(DB::raw(1))
                        ->from('evidence_links as el2')
                        ->join('price_list_versions as plv2', function ($j) {
                            $j->on('plv2.id', '=', 'el2.linkable_id')
                              ->where('el2.linkable_type', 'price_list_version');
                        })
                        ->join('price_lists as pl2', 'pl2.id', '=', 'plv2.price_list_id')
                        ->join('suppliers as s2', 's2.id', '=', 'pl2.supplier_id')
                        ->whereColumn('el2.evidence_record_id', 'evidence_records.id')
                        ->where('s2.user_id', $userId);
                })
                // Operation-linked manual evidence is user-scoped by creator, not supplier chain
                ->orWhere(function ($manual) use ($userId) {
                    $manual->where('evidence_records.created_by', $userId)
                        ->whereExists(function ($sub) {
                            $sub->select(DB::raw(1))
                                ->from('evidence_links as el3')
                                ->whereColumn('el3.evidence_record_id', 'evidence_records.id')
                                ->where('el3.linkable_type', 'operation');
                        });
                });
            });

        if ($request->filled('verification_status')) {
            $query->where('verification_status', $request->input('verification_status'));
        }

        if ($request->filled('source_type')) {
            $query->where('source_type', $request->input('source_type'));
        }

        if ($request->filled('linkable_type')) {
            $lt  = $request->input('linkable_type');
            $lid = $request->input('linkable_id');
            $query->whereHas('links', function ($q) use ($lt, $lid) {
                $q->where('linkable_type', $lt);
                if ($lid !== null) {
                    $q->where('linkable_id', (int) $lid);
                }
            });
        }

        if ($request->has('has_assets')) {
            if ($request->boolean('has_assets')) {
                $query->whereHas('assets');
            } else {
                $query->whereDoesntHave('assets');
            }
        }

        $perPage   = (int) $request->input('per_page', 15);
        $paginator = $query
            ->withCount('assets')
            ->with('links')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $items = collect($paginator->items())->map(function (EvidenceRecord $r) {
            return [
                'id'                  => $r->id,
                'observed_price'      => $r->observed_price,
                'currency'            => $r->currency,
                'source_type'         => $r->source_type,
                'verification_status' => $r->verification_status,
                'created_at'          => $r->created_at?->toIso8601String(),
                'assets_count'        => $r->assets_count,
                'linked_targets'      => $r->links->map(fn (EvidenceLink $link) => [
                    'type' => $link->linkable_type,
                    'id'   => $link->linkable_id,
                ])->values()->all(),
            ];
        });

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
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
     * GET /api/projects/{project}/evidence-runs/{runId}/items/{itemId}/candidates
     *
     * Strict picker: returns only records that are valid candidates for the
     * given evidence item (same component + normalized URL + non-rejected +
     * has proof asset).
     */
    public function searchCandidatesForItem(
        Request $request,
        Project $project,
        int $runId,
        int $itemId,
    ): JsonResponse {
        $this->authorize('view', $project);

        $run = EstimateEvidenceRun::where('project_id', $project->id)
            ->findOrFail($runId);

        $item = $run->items()->findOrFail($itemId);

        $request->validate([
            'q'        => 'nullable|string|max:200',
            'per_page' => 'nullable|integer|min:1|max:50',
            'page'     => 'nullable|integer|min:1',
        ]);

        $perPage = (int) $request->input('per_page', 20);
        $page = (int) $request->input('page', 1);

        $records = $this->confirmationService->getCandidatesForItem(
            $item->cost_component,
            $item->source_url,
            $request->input('q'),
            $perPage,
            $page,
        );

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
