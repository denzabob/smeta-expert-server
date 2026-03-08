<?php

namespace App\Services;

use App\Dto\EvidencePipelineResult;
use App\Evidence\EvidenceFeatures;
use App\Evidence\EvidenceItemState;
use App\Evidence\EvidenceReasonCode;
use App\Evidence\EvidenceStage;
use App\Models\EvidenceArtifact;
use App\Models\Material;
use App\Models\MaterialPriceHistory;
use App\Models\ProjectPosition;
use App\Models\RevisionRunItem;
use Illuminate\Support\Str;
use Throwable;

class EvidencePipelineService
{
    /** Ordered pipeline stages. */
    private const STAGES = [
        EvidenceStage::INIT,
        EvidenceStage::FETCH,
        EvidenceStage::PAGE_CLASSIFY,
        EvidenceStage::EXTRACT,
        EvidenceStage::CAPTURE,
        EvidenceStage::VALIDATE,
        EvidenceStage::PERSIST_ARTIFACT,
        EvidenceStage::LINK_HISTORY,
        EvidenceStage::LINK_REVISION,
        EvidenceStage::DONE,
    ];

    private array $diagnostics = [];
    private array $completedStages = [];
    private array $timings = [];
    private float $pipelineStart;

    public function __construct(
        private readonly UrlNormalizer            $urlNormalizer,
        private readonly MaterialParseService     $parseService,
        private readonly ScreenshotCaptureService $captureService,
    ) {}

    /* ================================================================
     *  PUBLIC ENTRY POINT
     * ================================================================ */

    /**
     * Process a single RevisionRunItem through the evidence pipeline.
     *
     * Caller is responsible for checking EvidenceFeatures::pipelineV2Enabled()
     * before invoking this method.
     */
    public function process(int $revisionRunItemId): EvidencePipelineResult
    {
        $this->pipelineStart = microtime(true);
        $this->diagnostics = [];
        $this->completedStages = [];
        $this->timings = [];

        $item = RevisionRunItem::with([
            'run.project',
            'material',
            'position.material',
            'position.edgeMaterial',
            'position.facadeMaterial',
        ])->find($revisionRunItemId);

        if (!$item) {
            return EvidencePipelineResult::skipped('RevisionRunItem not found');
        }

        // Mark running
        $this->transitionItem($item, EvidenceItemState::RUNNING, EvidenceStage::INIT);
        $item->increment('attempt_count');

        try {
            return $this->runPipeline($item);
        } catch (Throwable $e) {
            return $this->handleFatalError($item, $e);
        }
    }

    /* ================================================================
     *  PIPELINE ORCHESTRATION
     * ================================================================ */

    private function runPipeline(RevisionRunItem $item): EvidencePipelineResult
    {
        // ── INIT ─────────────────────────────────────────────────────
        $initResult = $this->stageInit($item);
        if ($initResult !== null) {
            return $initResult;
        }

        // ── FETCH (resolve material + URL) ───────────────────────────
        $fetchResult = $this->stageFetch($item);
        if ($fetchResult instanceof EvidencePipelineResult) {
            return $fetchResult;
        }
        /** @var array{material: Material, rawUrl: string, normalizedUrl: string, regionId: int|null} $fetchResult */
        $material      = $fetchResult['material'];
        $rawUrl        = $fetchResult['rawUrl'];
        $normalizedUrl = $fetchResult['normalizedUrl'];
        $regionId      = $fetchResult['regionId'];

        // ── PAGE_CLASSIFY (placeholder heuristic) ────────────────────
        $pageType = $this->stagePageClassify($rawUrl);

        // ── EXTRACT (parse price via existing service) ───────────────
        $extractResult = $this->stageExtract($item, $rawUrl, $material, $regionId);
        if ($extractResult instanceof EvidencePipelineResult) {
            return $extractResult;
        }
        /** @var array{price: float, name: string, article: string|null, parseConfidence: int, parseSessionId: int|null} $extractResult */

        // ── CAPTURE (screenshot) ─────────────────────────────────────
        $captureResult = $this->stageCapture($item, $rawUrl, $extractResult['price'], $regionId, $material->id);
        if ($captureResult instanceof EvidencePipelineResult) {
            return $captureResult;
        }
        /** @var array{screenshotPath: string} $captureResult */

        // ── VALIDATE ─────────────────────────────────────────────────
        $validationOk = $this->stageValidate(
            $item,
            $extractResult['price'],
            $captureResult['screenshotPath'],
            $normalizedUrl,
        );
        if (!$validationOk) {
            return $this->failItem(
                $item,
                EvidenceStage::VALIDATE,
                EvidenceReasonCode::MANUAL_REQUIRED,
                'Validation failed: missing required fields for auto_verified',
            );
        }

        // ── PERSIST_ARTIFACT ─────────────────────────────────────────
        $artifact = $this->stagePersistArtifact(
            $item, $material, $rawUrl, $normalizedUrl, $regionId,
            $extractResult, $captureResult, $pageType,
        );

        // ── LINK_HISTORY ─────────────────────────────────────────────
        $history = $this->stageLinkHistory(
            $item, $material, $rawUrl, $normalizedUrl, $regionId,
            $extractResult, $captureResult, $artifact,
        );

        // ── LINK_REVISION ────────────────────────────────────────────
        $this->stageLinkRevision($item, $history, $material, $normalizedUrl);

        // ── DONE ─────────────────────────────────────────────────────
        $this->completeStage(EvidenceStage::DONE);
        $this->transitionItem($item, EvidenceItemState::AUTO_VERIFIED, EvidenceStage::DONE);

        return EvidencePipelineResult::ok(
            artifactId:     $artifact->id,
            priceHistoryId: $history->id,
            extractedPrice: $extractResult['price'],
            screenshotPath: $captureResult['screenshotPath'],
            currency:       'RUB',
            diagnostics:    $this->buildDiagnostics(),
        );
    }

    /* ================================================================
     *  STAGE IMPLEMENTATIONS
     * ================================================================ */

    /**
     * INIT — exclude facade positions (same logic as legacy).
     */
    private function stageInit(RevisionRunItem $item): ?EvidencePipelineResult
    {
        $start = microtime(true);
        $this->transitionItem($item, EvidenceItemState::RUNNING, EvidenceStage::INIT);

        $isFacade = $item->position?->kind === ProjectPosition::KIND_FACADE
            || ($item->material?->type ?? null) === Material::TYPE_FACADE
            || ($item->position?->facadeMaterial?->type ?? null) === Material::TYPE_FACADE;

        if ($isFacade) {
            $this->completeStage(EvidenceStage::INIT, $start);
            // Legacy compat: mark OK + skip
            $item->update([
                'status'  => RevisionRunItem::STATUS_OK,
                'state'   => EvidenceItemState::AUTO_VERIFIED,
                'stage'   => EvidenceStage::DONE,
                'message' => 'Фасады исключены из автосбора обоснований',
            ]);
            return EvidencePipelineResult::skipped('Фасады исключены из автосбора обоснований', $this->buildDiagnostics());
        }

        $this->completeStage(EvidenceStage::INIT, $start);
        return null;
    }

    /**
     * FETCH — resolve material entity and source URL.
     *
     * @return array|EvidencePipelineResult payload or early-exit result
     */
    private function stageFetch(RevisionRunItem $item): array|EvidencePipelineResult
    {
        $start = microtime(true);
        $this->transitionItem($item, EvidenceItemState::RUNNING, EvidenceStage::FETCH);

        $material = $item->material
            ?: $item->position?->facadeMaterial
            ?: $item->position?->edgeMaterial
            ?: $item->position?->material;

        if (!$material) {
            $this->completeStage(EvidenceStage::FETCH, $start);
            return $this->failItem($item, EvidenceStage::FETCH, EvidenceReasonCode::MANUAL_REQUIRED, 'Материал позиции не найден');
        }

        $rawUrl = (string) ($material->source_url ?: $item->source_url);
        $normalizedUrl = $this->urlNormalizer->normalize($rawUrl);

        if (!$normalizedUrl) {
            $this->completeStage(EvidenceStage::FETCH, $start);
            return $this->failItem($item, EvidenceStage::FETCH, EvidenceReasonCode::MANUAL_REQUIRED, 'Отсутствует URL источника');
        }

        $regionId = $item->run?->project?->region_id;

        $this->diagnostics['source_url_raw'] = $rawUrl;
        $this->diagnostics['source_url_normalized'] = $normalizedUrl;
        $this->diagnostics['material_id'] = $material->id;
        $this->completeStage(EvidenceStage::FETCH, $start);

        return compact('material', 'rawUrl', 'normalizedUrl', 'regionId');
    }

    /**
     * PAGE_CLASSIFY — placeholder heuristic based on domain patterns.
     * Full ProductPageValidator will be implemented in a later stage.
     */
    private function stagePageClassify(string $rawUrl): ?string
    {
        $start = microtime(true);

        $host = parse_url($rawUrl, PHP_URL_HOST) ?? '';
        $path = parse_url($rawUrl, PHP_URL_PATH) ?? '';

        // Simple heuristic: catalog pages usually have /catalog/ or /product/ in path
        $pageType = null;
        if (preg_match('#/(product|tovar|item|p)/[^/]+#i', $path)) {
            $pageType = 'product';
        } elseif (preg_match('#/(catalog|category|categories|cat)/#i', $path)) {
            $pageType = 'catalog';
        }

        $this->diagnostics['page_classify'] = [
            'page_type' => $pageType,
            'host' => $host,
            'heuristic' => true,
        ];
        $this->completeStage(EvidenceStage::PAGE_CLASSIFY, $start);

        return $pageType;
    }

    /**
     * EXTRACT — parse price/name/article via existing MaterialParseService.
     *
     * @return array|EvidencePipelineResult extracted data or early-exit result
     */
    private function stageExtract(
        RevisionRunItem $item,
        string $rawUrl,
        Material $material,
        ?int $regionId,
    ): array|EvidencePipelineResult {
        $start = microtime(true);
        $this->transitionItem($item, EvidenceItemState::RUNNING, EvidenceStage::EXTRACT);

        $parseResult = $this->parseService->parseByUrl($rawUrl, (string) $material->type, $regionId);

        $parsedPrice   = (float) ($parseResult['data']['price_per_unit'] ?? 0);
        $parsedName    = trim((string) ($parseResult['data']['name'] ?? $material->name));
        $parsedArticle = trim((string) ($parseResult['data']['article'] ?? '')) ?: null;
        $parseStatus   = (string) ($parseResult['parse_status'] ?? 'error');
        $confidence    = (int) ($parseResult['confidence'] ?? 0);
        $sessionId     = $parseResult['parse_session_id'] ?? null;

        $this->diagnostics['extract'] = [
            'parse_status' => $parseStatus,
            'price'        => $parsedPrice,
            'confidence'   => $confidence,
            'has_name'     => $parsedName !== '',
            'has_article'  => $parsedArticle !== null,
        ];
        $this->completeStage(EvidenceStage::EXTRACT, $start);

        if ($parsedPrice <= 0) {
            $reasonCode = match ($parseStatus) {
                'blocked' => EvidenceReasonCode::BLOCK_CLOUDFLARE,
                default   => EvidenceReasonCode::PRICE_NOT_FOUND,
            };
            return $this->failItem($item, EvidenceStage::EXTRACT, $reasonCode, 'Цена не извлечена');
        }

        return [
            'price'           => $parsedPrice,
            'name'            => $parsedName,
            'article'         => $parsedArticle,
            'parseConfidence' => $confidence,
            'parseSessionId'  => $sessionId,
        ];
    }

    /**
     * CAPTURE — take screenshot via existing ScreenshotCaptureService.
     *
     * Before capturing, check for existing recent observation to avoid duplicate work.
     *
     * @return array|EvidencePipelineResult capture payload or early-exit result
     */
    private function stageCapture(
        RevisionRunItem $item,
        string $rawUrl,
        float $price,
        ?int $regionId,
        int $materialId,
    ): array|EvidencePipelineResult {
        $start = microtime(true);
        $this->transitionItem($item, EvidenceItemState::RUNNING, EvidenceStage::CAPTURE);

        // Check for existing recent observation (same logic as legacy — avoid duplicate screenshots)
        $normalizedUrl = $this->urlNormalizer->normalize($rawUrl);
        $existing = MaterialPriceHistory::where('normalized_source_url', $normalizedUrl)
            ->where('price_per_unit', $price)
            ->where('currency', 'RUB')
            ->where('region_id', $regionId)
            ->whereNotNull('screenshot_path')
            ->latest('id')
            ->first();

        if ($existing) {
            $this->diagnostics['capture'] = [
                'reused_history_id' => $existing->id,
                'screenshot_path'   => $existing->screenshot_path,
            ];
            $this->completeStage(EvidenceStage::CAPTURE, $start);
            return ['screenshotPath' => $existing->screenshot_path, 'reusedHistoryId' => $existing->id];
        }

        $shot = $this->captureService->captureByUrl(
            url: $rawUrl,
            price: $price,
            currency: 'RUB',
            regionId: $regionId,
            materialId: $materialId,
            revisionRunItemId: $item->id,
        );

        $shotStatus = (string) ($shot['status'] ?? 'error');

        $this->diagnostics['capture'] = [
            'status' => $shotStatus,
            'screenshot_path' => $shot['screenshot_path'] ?? null,
        ];
        $this->completeStage(EvidenceStage::CAPTURE, $start);

        if ($shotStatus !== 'ok') {
            $reasonCode = match ($shotStatus) {
                'blocked' => EvidenceReasonCode::BLOCK_CLOUDFLARE,
                'timeout' => EvidenceReasonCode::BROWSER_TIMEOUT,
                default   => EvidenceReasonCode::SCREENSHOT_EMPTY,
            };
            return $this->failItem($item, EvidenceStage::CAPTURE, $reasonCode, 'Не удалось получить скриншот');
        }

        return ['screenshotPath' => $shot['screenshot_path'], 'reusedHistoryId' => null];
    }

    /**
     * VALIDATE — ensure minimum conditions for auto_verified.
     *
     * Product rule: auto_verified requires source + price + screenshot + no errors.
     */
    private function stageValidate(
        RevisionRunItem $item,
        float $price,
        string $screenshotPath,
        string $normalizedUrl,
    ): bool {
        $start = microtime(true);
        $this->transitionItem($item, EvidenceItemState::RUNNING, EvidenceStage::VALIDATE);

        $valid = $price > 0
            && $screenshotPath !== ''
            && $normalizedUrl !== '';

        $this->diagnostics['validate'] = [
            'price_positive'    => $price > 0,
            'has_screenshot'    => $screenshotPath !== '',
            'has_url'           => $normalizedUrl !== '',
            'result'            => $valid ? 'pass' : 'fail',
        ];
        $this->completeStage(EvidenceStage::VALIDATE, $start);

        return $valid;
    }

    /**
     * PERSIST_ARTIFACT — create EvidenceArtifact record.
     */
    private function stagePersistArtifact(
        RevisionRunItem $item,
        Material $material,
        string $rawUrl,
        string $normalizedUrl,
        ?int $regionId,
        array $extractResult,
        array $captureResult,
        ?string $pageType,
    ): EvidenceArtifact {
        $start = microtime(true);
        $this->transitionItem($item, EvidenceItemState::RUNNING, EvidenceStage::PERSIST_ARTIFACT);

        $domain = parse_url($rawUrl, PHP_URL_HOST) ?: null;

        $artifact = EvidenceArtifact::create([
            'uuid'                  => (string) Str::uuid(),
            'material_id'           => $material->id,
            'revision_run_id'       => $item->revision_run_id,
            'revision_run_item_id'  => $item->id,
            'mode'                  => EvidenceArtifact::MODE_AUTO,
            'source_url_raw'        => $rawUrl,
            'source_url_normalized' => $normalizedUrl,
            'source_domain'         => $domain,
            'page_type'             => $pageType,
            'extracted_price'       => $extractResult['price'],
            'currency'              => 'RUB',
            'extracted_name'        => $extractResult['name'] ?? null,
            'extracted_article'     => $extractResult['article'] ?? null,
            'screenshot_path'       => $captureResult['screenshotPath'],
            'confidence_score'      => $extractResult['parseConfidence'] ?? null,
            'captured_at'           => now(),
        ]);

        $this->diagnostics['artifact_id'] = $artifact->id;
        $this->completeStage(EvidenceStage::PERSIST_ARTIFACT, $start);

        return $artifact;
    }

    /**
     * LINK_HISTORY — create MaterialPriceHistory linked to artifact.
     *
     * If a reused history was found during CAPTURE, link to that instead.
     */
    private function stageLinkHistory(
        RevisionRunItem $item,
        Material $material,
        string $rawUrl,
        string $normalizedUrl,
        ?int $regionId,
        array $extractResult,
        array $captureResult,
        EvidenceArtifact $artifact,
    ): MaterialPriceHistory {
        $start = microtime(true);
        $this->transitionItem($item, EvidenceItemState::RUNNING, EvidenceStage::LINK_HISTORY);

        $reusedId = $captureResult['reusedHistoryId'] ?? null;
        if ($reusedId) {
            $history = MaterialPriceHistory::find($reusedId);
            if ($history) {
                // Link artifact to existing history if not yet linked
                if (!$history->evidence_artifact_id) {
                    $history->update([
                        'evidence_artifact_id' => $artifact->id,
                        'evidence_mode'        => EvidenceArtifact::MODE_AUTO,
                        'is_auto_verified'     => true,
                        'validation_confidence' => $extractResult['parseConfidence'] ?? null,
                    ]);
                }
                $this->diagnostics['link_history'] = ['reused' => true, 'history_id' => $history->id];
                $this->completeStage(EvidenceStage::LINK_HISTORY, $start);
                return $history;
            }
        }

        $history = MaterialPriceHistory::create([
            'material_id'           => $material->id,
            'version'               => (int) ($material->version ?? 1),
            'valid_from'            => now()->toDateString(),
            'price_per_unit'        => $extractResult['price'],
            'source_url'            => $normalizedUrl,
            'raw_source_url'        => $rawUrl,
            'normalized_source_url' => $normalizedUrl,
            'screenshot_path'       => $captureResult['screenshotPath'],
            'observed_at'           => now(),
            'region_id'             => $regionId,
            'source_type'           => MaterialPriceHistory::SOURCE_WEB,
            'is_verified'           => true,
            'true_score'            => 100,
            'currency'              => 'RUB',
            'evidence_artifact_id'  => $artifact->id,
            'evidence_mode'         => EvidenceArtifact::MODE_AUTO,
            'is_auto_verified'      => true,
            'validation_confidence' => $extractResult['parseConfidence'] ?? null,
        ]);

        $this->diagnostics['link_history'] = ['reused' => false, 'history_id' => $history->id];
        $this->completeStage(EvidenceStage::LINK_HISTORY, $start);

        return $history;
    }

    /**
     * LINK_REVISION — update the RevisionRunItem with result references.
     */
    private function stageLinkRevision(
        RevisionRunItem $item,
        MaterialPriceHistory $history,
        Material $material,
        string $normalizedUrl,
    ): void {
        $start = microtime(true);
        $this->transitionItem($item, EvidenceItemState::RUNNING, EvidenceStage::LINK_REVISION);

        $item->update([
            'status'           => RevisionRunItem::STATUS_OK,
            'message'          => 'Evidence pipeline v2: auto verified',
            'source_url'       => $normalizedUrl,
            'material_id'      => $material->id,
            'price_history_id' => $history->id,
        ]);

        $this->completeStage(EvidenceStage::LINK_REVISION, $start);
    }

    /* ================================================================
     *  HELPERS
     * ================================================================ */

    /**
     * Transition item stage/state and persist to database.
     */
    private function transitionItem(RevisionRunItem $item, string $state, string $stage): void
    {
        $item->update([
            'state' => $state,
            'stage' => $stage,
        ]);
    }

    /**
     * Mark a pipeline failure — always uses manual_required (safest choice).
     */
    private function failItem(
        RevisionRunItem $item,
        string $failedStage,
        string $reasonCode,
        string $message,
    ): EvidencePipelineResult {
        $diag = $this->buildDiagnostics($message);

        // Map reason to legacy status for backward compat
        $legacyStatus = match ($reasonCode) {
            EvidenceReasonCode::BLOCK_CLOUDFLARE,
            EvidenceReasonCode::BLOCK_CAPTCHA,
            EvidenceReasonCode::BLOCK_403,
            EvidenceReasonCode::BLOCK_429      => RevisionRunItem::STATUS_BLOCKED,
            EvidenceReasonCode::HTTP_TIMEOUT,
            EvidenceReasonCode::BROWSER_TIMEOUT => RevisionRunItem::STATUS_TIMEOUT,
            default                             => RevisionRunItem::STATUS_NEEDS_MANUAL,
        };

        $item->update([
            'state'            => EvidenceItemState::MANUAL_REQUIRED,
            'stage'            => $failedStage,
            'status'           => $legacyStatus,
            'reason_code'      => $reasonCode,
            'message'          => $message,
            'diagnostics_json' => $diag,
            'last_error_at'    => now(),
        ]);

        return EvidencePipelineResult::manualRequired($failedStage, $reasonCode, $diag);
    }

    /**
     * Handle unexpected exception — catch-all safety net.
     */
    private function handleFatalError(RevisionRunItem $item, Throwable $e): EvidencePipelineResult
    {
        $message = 'Pipeline exception: ' . $e->getMessage();
        $this->diagnostics['exception'] = [
            'class'   => get_class($e),
            'message' => mb_substr($e->getMessage(), 0, 500),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
        ];

        return $this->failItem(
            $item,
            $item->stage ?: EvidenceStage::INIT,
            EvidenceReasonCode::MANUAL_REQUIRED,
            $message,
        );
    }

    private function completeStage(string $stage, ?float $start = null): void
    {
        $this->completedStages[] = $stage;
        if ($start !== null) {
            $this->timings[$stage] = round((microtime(true) - $start) * 1000, 1);
        }
    }

    private function buildDiagnostics(?string $errorMessage = null): array
    {
        $diag = $this->diagnostics;
        $diag['completed_stages'] = $this->completedStages;
        $diag['timings_ms'] = $this->timings;
        $diag['total_ms'] = round((microtime(true) - $this->pipelineStart) * 1000, 1);

        if ($errorMessage !== null) {
            $diag['error_message'] = $errorMessage;
        }

        return $diag;
    }
}
