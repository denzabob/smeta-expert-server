<?php

namespace App\Http\Controllers\Api;

use App\Evidence\CostComponent;
use App\Evidence\EvidenceFeatures;
use App\Evidence\EvidenceItemStatus;
use App\Evidence\EvidenceRunStatus;
use App\Http\Controllers\Concerns\RecordsUsageEvents;
use App\Http\Controllers\Controller;
use App\Models\ChromeExtLog;
use App\Models\EstimateEvidenceItem;
use App\Models\EstimateEvidenceRun;
use App\Services\Billing\BillingCodes;
use App\Services\ChromeExtractService;
use App\Services\GenericChromeCaptureService;
use App\Services\TrustScoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GenericChromeController extends Controller
{
    use RecordsUsageEvents;

    /**
     * Whitelist mapping: Material::TYPE_* → CostComponent::*.
     * Only these stable mappings are used for auto-link derivation.
     */
    private const MATERIAL_TYPE_TO_COST_COMPONENT = [
        'plate'    => CostComponent::PLATE,
        'edge'     => CostComponent::EDGE,
        'facade'   => CostComponent::FACADE,
        'hardware' => CostComponent::FITTING,
    ];

    public function __construct(
        private GenericChromeCaptureService $captureService,
        private ChromeExtractService $extractService,
    ) {}

    /**
     * GET /api/chrome/generic-items
     * List open evidence items across the user's active runs.
     */
    public function listGenericItems(Request $request): JsonResponse
    {
        if (!EvidenceFeatures::genericChromeEnabled()) {
            abort(404);
        }

        $userId = $request->user()->id;

        $items = EstimateEvidenceItem::whereHas('run', function ($q) use ($userId) {
                $q->where('initiated_by', $userId)
                  ->whereNotIn('status', EvidenceRunStatus::terminalStatuses());
            })
            ->whereNotIn('status', EvidenceItemStatus::terminalStatuses())
            ->with([
                'run:id,project_id,status,uuid',
                'run.project:id,number,expert_name',
            ])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $mapped = $items->map(function (EstimateEvidenceItem $item) {
            return [
                'id'             => $item->id,
                'uuid'           => $item->uuid,
                'evidence_run_id' => $item->evidence_run_id,
                'cost_component' => $item->cost_component,
                'label'          => $item->label,
                'status'         => $item->status,
                'source_url'     => $item->source_url,
                'effective_value' => $item->effective_value,
                'currency'       => $item->currency,
                'project_name'   => $item->run?->project?->number
                    ? ($item->run->project->number . ' — ' . ($item->run->project->expert_name ?? ''))
                    : null,
                'run_status'     => $item->run?->status,
            ];
        });

        return response()->json([
            'items' => $mapped->values(),
            'total' => $mapped->count(),
        ]);
    }

    /**
     * POST /api/chrome/capture-observation
     * Create a standalone evidence record from Chrome capture (not tied to any run item).
     */
    public function captureObservation(Request $request): JsonResponse
    {
        if (!EvidenceFeatures::genericChromeEnabled()) {
            abort(404);
        }

        $validated = $request->validate([
            'cost_component'     => 'required|string|in:' . implode(',', CostComponent::all()),
            'source_url'         => 'required|url|max:2048',
            'observed_price'     => 'nullable|numeric|min:0',
            'currency'           => 'nullable|string|max:10',
            'extracted_name'     => 'nullable|string|max:500',
            'extracted_article'  => 'nullable|string|max:255',
            'confidence_score'   => 'nullable|integer|min:0|max:100',
            'capture_mode'       => 'nullable|string|max:50',
            'template_id'        => 'nullable|integer',
            'screenshot_file'    => 'nullable|file|image|max:10240',
            'field_sources_json' => 'nullable|json',
            'selectors_json'     => 'nullable|json',
            'browser_context_json' => 'nullable|json',
            'annotation_map_json'  => 'nullable|json',
        ]);

        $screenshot = $request->file('screenshot_file');
        $userId = $request->user()->id;

        $result = $this->captureService->captureObservation($validated, $userId, $screenshot);

        $this->logChromeAction($request, 'capture_observation', $result['duplicate'] ? 'duplicate' : 'ok', $result['record']);

        $status = $result['duplicate'] ? 200 : 201;

        $this->recordUsageEvent(BillingCodes::METRIC_EVIDENCE_CHROME_CAPTURES, 1, [
            'user' => $request->user(),
            'feature_code' => BillingCodes::FEATURE_EVIDENCE_CHROME_CAPTURE,
            'subject_type' => get_class($result['record']),
            'subject_id' => $result['record']->id,
            'unit' => 'count',
            'source' => 'chrome_extension',
            'metadata' => [
                'controller' => static::class,
                'action' => __FUNCTION__,
                'duplicate' => $result['duplicate'],
                'cost_component' => $validated['cost_component'],
            ],
            'idempotency_key' => hash('sha256', implode('|', [
                BillingCodes::METRIC_EVIDENCE_CHROME_CAPTURES,
                $request->user()->id,
                $validated['source_url'],
                $validated['cost_component'],
                $validated['observed_price'] ?? '',
                now()->format('Y-m-d H:i'),
            ])),
        ]);

        return response()->json([
            'success'   => true,
            'duplicate' => $result['duplicate'],
            'data'      => [
                'record_id' => $result['record']->id,
                'record_uuid' => $result['record']->uuid,
                'asset_id' => $result['asset']?->id,
            ],
        ], $status);
    }

    /**
     * POST /api/chrome/generic-items/{itemId}/capture
     * Submit evidence for a specific generic evidence item from the extension.
     */
    public function captureGenericItem(Request $request, int $itemId): JsonResponse
    {
        if (!EvidenceFeatures::genericChromeEnabled()) {
            abort(404);
        }

        $validated = $request->validate([
            'source_url'         => 'required|url|max:2048',
            'observed_price'     => 'nullable|numeric|min:0',
            'currency'           => 'nullable|string|max:10',
            'extracted_name'     => 'nullable|string|max:500',
            'extracted_article'  => 'nullable|string|max:255',
            'confidence_score'   => 'nullable|integer|min:0|max:100',
            'capture_mode'       => 'nullable|string|max:50',
            'template_id'        => 'nullable|integer',
            'screenshot_file'    => 'nullable|file|image|max:10240',
            'field_sources_json' => 'nullable|json',
            'selectors_json'     => 'nullable|json',
            'browser_context_json' => 'nullable|json',
            'annotation_map_json'  => 'nullable|json',
        ]);

        $item = EstimateEvidenceItem::with('run.project')->findOrFail($itemId);

        // Access check: only the run initiator can capture for their items
        if ($item->run->initiated_by !== $request->user()->id) {
            return response()->json(['success' => false, 'error' => 'Access denied.'], 403);
        }

        $screenshot = $request->file('screenshot_file');
        $userId = $request->user()->id;

        $result = $this->captureService->captureForItem($item, $validated, $userId, $screenshot);

        if (isset($result['error'])) {
            return response()->json([
                'success' => false,
                'error'   => $result['error'],
            ], 422);
        }

        $this->logChromeAction($request, 'capture_generic_item', $result['duplicate'] ? 'duplicate' : 'ok', $result['record']);

        $status = $result['duplicate'] ? 200 : 201;

        $this->recordUsageEvent(BillingCodes::METRIC_EVIDENCE_CHROME_ITEM_CAPTURES, 1, [
            'project' => $item->run->project,
            'user' => $request->user(),
            'feature_code' => BillingCodes::FEATURE_EVIDENCE_CHROME_CAPTURE,
            'subject_type' => EstimateEvidenceItem::class,
            'subject_id' => $item->id,
            'unit' => 'count',
            'source' => 'chrome_extension',
            'metadata' => [
                'controller' => static::class,
                'action' => __FUNCTION__,
                'duplicate' => $result['duplicate'],
                'record_id' => $result['record']->id,
                'evidence_run_id' => $item->evidence_run_id,
            ],
            'idempotency_key' => hash('sha256', implode('|', [
                BillingCodes::METRIC_EVIDENCE_CHROME_ITEM_CAPTURES,
                $request->user()->id,
                $item->id,
                $validated['source_url'],
                $validated['observed_price'] ?? '',
                now()->format('Y-m-d H:i'),
            ])),
        ]);

        return response()->json([
            'success'   => true,
            'duplicate' => $result['duplicate'],
            'item_id'   => $item->id,
            'data'      => [
                'record_id'   => $result['record']->id,
                'record_uuid' => $result['record']->uuid,
                'asset_id'    => $result['asset']?->id,
                'item_status' => $item->fresh()->status,
            ],
        ], $status);
    }

    /**
     * POST /api/chrome/extract-with-evidence
     * One-click material upsert + evidence record + screenshot + auto-link.
     *
     * Accepts the same material fields as POST /chrome/extract, plus an optional
     * screenshot_file. Always performs material upsert. If generic evidence feature
     * is enabled, also creates an EvidenceRecord (with screenshot when provided)
     * and attempts deterministic auto-link to a matching unresolved evidence item.
     *
     * Response contract axes:
     *  - material_status: 'created' | 'updated'
     *  - evidence_status: 'created' | 'duplicate' | 'skipped_feature_disabled'
     *  - screenshot_status: 'captured' | 'failed' | 'skipped'
     *  - auto_link: { linked: bool, item_id?: int, item_label?: string } | null
     */
    public function extractWithEvidence(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url'                  => 'required|url|max:2048',
            'extracted'            => 'required|array',
            'extracted.title'      => 'required|string|max:500',
            'extracted.price'      => 'required|string|max:100',
            'extracted.article'    => 'nullable|string|max:255',
            'extracted.thickness'  => 'nullable|string|max:20',
            'extracted.length'     => 'nullable|string|max:20',
            'extracted.width'      => 'nullable|string|max:20',
            'data_sources'         => 'nullable|array',
            'data_sources.*'       => 'nullable|string|in:auto,capture,schema,manual',
            'template_id'          => 'nullable|integer|exists:parser_supplier_collect_profiles,id',
            'region_id'            => 'nullable|integer|exists:regions,id',
            'screenshot_file'      => 'nullable|file|image|max:10240',
        ]);

        $user = $request->user();
        $regionId = $validated['region_id'] ?? $user->settings?->region_id;
        $sourceHost = parse_url($validated['url'], PHP_URL_HOST) ?: null;

        $this->checkBillingGateSafely($user, BillingCodes::CAP_CHROME_CAPTURES_MONTHLY_LIMIT, [
            'action' => 'chrome.extract_with_evidence',
            'source_host' => $sourceHost,
            'component_type' => null,
        ]);

        // ── Phase 1: Material upsert (always runs) ──
        $materialResult = $this->extractService->createOrUpdateMaterial(
            userId: $user->id,
            url: $validated['url'],
            extractedFields: $validated['extracted'],
            regionId: $regionId,
            templateId: $validated['template_id'] ?? null,
            dataSources: $validated['data_sources'] ?? [],
        );

        if ($materialResult['status'] === 'failed') {
            return response()->json([
                'success'           => false,
                'material_status'   => 'failed',
                'evidence_status'   => 'skipped',
                'screenshot_status' => 'skipped',
                'auto_link'         => null,
                'errors'            => $materialResult['errors'],
                'message'           => 'Не удалось создать материал: ' . implode('; ', $materialResult['errors']),
            ], 422);
        }

        $material = $materialResult['material'];
        $materialStatus = $materialResult['is_new'] ? 'created' : 'updated';
        $screenshotStatus = 'skipped';
        $evidenceStatus = 'skipped_feature_disabled';
        $autoLink = null;
        $evidenceData = null;
        $componentType = null;

        // ── Phase 2: Evidence + screenshot (only when feature enabled) ──
        if (EvidenceFeatures::genericChromeEnabled()) {
            $derivedComponent = self::MATERIAL_TYPE_TO_COST_COMPONENT[$material->type] ?? null;
            $componentType = $derivedComponent;

            if ($derivedComponent) {
                $screenshot = $request->file('screenshot_file');
                $screenshotStatus = $screenshot ? 'captured' : 'failed';

                $price = ChromeExtractService::parsePrice($validated['extracted']['price'] ?? null);

                $evidencePayload = [
                    'cost_component'    => $derivedComponent,
                    'source_url'        => $validated['url'],
                    'observed_price'    => $price,
                    'currency'          => ChromeExtractService::parseCurrency($validated['extracted']['price'] ?? null),
                    'extracted_name'    => $validated['extracted']['title'] ?? null,
                    'extracted_article' => $validated['extracted']['article'] ?? null,
                    'capture_mode'      => 'one_click',
                ];

                $evidenceResult = $this->captureService->captureObservation(
                    $evidencePayload,
                    $user->id,
                    $screenshot
                );

                if ($evidenceResult['fresh_reuse'] ?? false) {
                    $evidenceStatus = 'reused_existing';
                } elseif ($evidenceResult['duplicate']) {
                    $evidenceStatus = 'duplicate';
                } else {
                    $evidenceStatus = 'created';
                }
                $evidenceData = [
                    'record_id'   => $evidenceResult['record']->id,
                    'record_uuid' => $evidenceResult['record']->uuid,
                    'asset_id'    => $evidenceResult['asset']?->id,
                ];

                // ── Bridge: write screenshot/evidence back to the price observation ──
                // Runs for BOTH new and duplicate evidence — this is the single
                // source-of-truth wiring so that material-detail, trust-score, and
                // confirmation logic all see the evidence screenshot regardless of
                // whether the evidence record was newly created or already existed.
                // For duplicates, $evidenceResult['asset'] is null; we look up the
                // existing screenshot asset from the duplicate record instead.
                if (isset($materialResult['observation'])) {
                    $bridgeData = ['evidence_record_id' => $evidenceResult['record']->id];
                    $bridgeAsset = $evidenceResult['asset']
                        ?? $evidenceResult['record']->assets()->where('asset_type', 'screenshot')->first();
                    if ($bridgeAsset && !empty($bridgeAsset->file_path)) {
                        $bridgeData['screenshot_path'] = $bridgeAsset->file_path;
                    }
                    $materialResult['observation']->update($bridgeData);
                }

                // ── Phase 3: Deterministic auto-link ──
                // Runs for BOTH new and duplicate evidence.
                // autoLinkToEvidenceItem is safe to call on duplicates: if the matching
                // item was already resolved (by a previous capture), it is excluded by
                // the whereNotIn(terminalStatuses()) filter and linked=false is returned.
                $autoLink = $this->captureService->autoLinkToEvidenceItem(
                    $evidenceResult['record'],
                    $user->id,
                    $derivedComponent,
                    $validated['url']
                );
            } else {
                // Cost component not derivable — evidence still skipped, material saved
                // Screenshot is irrelevant when evidence is intentionally skipped
                $evidenceStatus = 'skipped_unmapped_type';
                $screenshotStatus = 'skipped';
            }
        }

        // ── Phase 4: Recalculate trust score ──
        // Must run for BOTH created and updated materials so that the newly
        // written observation (with evidence_record_id + screenshot_path) is
        // reflected in the stored trust_score / trust_level.  For new materials
        // ChromeExtractService sets an initial 70/30, but that doesn't account
        // for the evidence bridge written above.  For updated materials the
        // stored trust_score was previously stale.
        app(TrustScoreService::class)->recalculate($material);

        $this->logChromeAction($request, 'extract_with_evidence', 'ok', null, $validated['url']);

        $this->recordUsageEvent(BillingCodes::METRIC_CHROME_EXTRACT_WITH_EVIDENCE, 1, [
            'user' => $user,
            'feature_code' => BillingCodes::FEATURE_CHROME_EXTRACT_WITH_EVIDENCE,
            'subject_type' => get_class($material),
            'subject_id' => $material->id,
            'unit' => 'count',
            'source' => 'chrome_extension',
            'metadata' => [
                'controller' => static::class,
                'action' => __FUNCTION__,
                'material_status' => $materialStatus,
                'evidence_status' => $evidenceStatus,
                'screenshot_status' => $screenshotStatus,
                'component_type' => $componentType,
            ],
            'idempotency_key' => hash('sha256', implode('|', [
                BillingCodes::METRIC_CHROME_EXTRACT_WITH_EVIDENCE,
                $user->id,
                $validated['url'],
                $componentType ?? '',
                $validated['extracted']['price'] ?? '',
                now()->format('Y-m-d H:i'),
            ])),
        ]);

        return response()->json([
            'success'           => true,
            'material'          => $material,
            'observation'       => $materialResult['observation'],
            'is_new'            => $materialResult['is_new'],
            'dedup_match'       => $materialResult['dedup_match'],
            'type_resolution'   => $materialResult['type_resolution'] ?? null,
            'material_status'   => $materialStatus,
            'evidence_status'   => $evidenceStatus,
            'screenshot_status' => $screenshotStatus,
            'auto_link'         => $autoLink,
            'evidence'          => $evidenceData,
            'errors'            => $materialResult['errors'],
            'message'           => $this->buildResultMessage($materialStatus, $evidenceStatus, $screenshotStatus, $autoLink),
        ], 201);
    }

    /**
     * Build a human-readable result message covering all axes.
     */
    private function buildResultMessage(string $materialStatus, string $evidenceStatus, string $screenshotStatus, ?array $autoLink): string
    {
        $parts = [];

        $parts[] = $materialStatus === 'created'
            ? 'Материал создан'
            : 'Материал обновлён';

        if ($evidenceStatus === 'created') {
            $parts[] = $screenshotStatus === 'captured'
                ? 'доказательство + скриншот сохранены'
                : 'доказательство сохранено (скриншот не удался)';
        } elseif ($evidenceStatus === 'duplicate') {
            $parts[] = 'доказательство найдено (дубликат)';
        } elseif ($evidenceStatus === 'reused_existing') {
            $parts[] = 'актуальное доказательство уже существует (переиспользовано)';
        } elseif ($evidenceStatus === 'skipped_feature_disabled') {
            $parts[] = 'доказательство не создано (функция отключена)';
        } elseif ($evidenceStatus === 'skipped_unmapped_type') {
            $parts[] = 'доказательство не создано (тип не распознан)';
        }

        if ($autoLink && $autoLink['linked']) {
            $label = $autoLink['item_label'] ?? '#' . $autoLink['item_id'];
            $parts[] = 'привязано к «' . $label . '»';
        }

        return implode('; ', $parts) . '.';
    }

    /**
     * Log chrome extension action using existing enum values.
     */
    private function logChromeAction(Request $request, string $action, string $status, $record = null, ?string $url = null): void
    {
        // Map new action names to existing enum('capture','save_template','extract','error')
        $actionMap = [
            'capture_observation'   => 'capture',
            'capture_generic_item'  => 'capture',
            'extract_with_evidence' => 'extract',
        ];

        // Map new status names to existing enum('success','partial','failed')
        $statusMap = [
            'ok'        => 'success',
            'duplicate' => 'partial',
            'error'     => 'failed',
        ];

        $logUrl = $url ?? $request->input('source_url', '');
        $domain = $record?->source_domain ?? (parse_url($logUrl, PHP_URL_HOST) ?: '');

        ChromeExtLog::create([
            'user_id' => $request->user()->id,
            'url'     => $logUrl,
            'domain'  => $domain,
            'action'  => $actionMap[$action] ?? 'capture',
            'status'  => $statusMap[$status] ?? 'success',
        ]);
    }
}
