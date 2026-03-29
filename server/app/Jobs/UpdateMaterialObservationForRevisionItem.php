<?php

namespace App\Jobs;

use App\Evidence\CostDriverType;
use App\Evidence\EvidenceFeatures;
use App\Models\MaterialPriceHistory;
use App\Models\RevisionRun;
use App\Models\RevisionRunItem;
use App\Services\EvidencePipelineService;
use App\Services\MaterialParseService;
use App\Services\ScreenshotCaptureService;
use App\Services\UrlNormalizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UpdateMaterialObservationForRevisionItem implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $revisionRunItemId
    ) {}

    public function handle(
        UrlNormalizer $urlNormalizer,
        MaterialParseService $parseService,
        ScreenshotCaptureService $captureService
    ): void {
        // ── Safety guard: internal-only items must never reach scraping ──
        $item = RevisionRunItem::find($this->revisionRunItemId);
        if ($item && in_array($item->cost_driver_type, CostDriverType::internalOnlyTypes(), true)) {
            return;
        }

        // ── Feature flag gate: delegate to new pipeline ──
        if (EvidenceFeatures::pipelineV2Enabled()) {
            $pipeline = app(EvidencePipelineService::class);
            $result = $pipeline->process($this->revisionRunItemId);

            // Pipeline updates RevisionRunItem internally.
            // Refresh run stats for consistency with legacy counters.
            $item = RevisionRunItem::find($this->revisionRunItemId);
            if ($item?->run) {
                $this->refreshRunStats($item->run, !$result->success);
            }
            return;
        }

        // ── Legacy flow (unchanged) ──
        $this->handleLegacy($urlNormalizer, $parseService, $captureService);
    }

    private function handleLegacy(
        UrlNormalizer $urlNormalizer,
        MaterialParseService $parseService,
        ScreenshotCaptureService $captureService
    ): void {
        $item = RevisionRunItem::with(['run.project', 'material', 'projectFitting.material', 'position.material', 'position.edgeMaterial', 'position.facadeMaterial'])->find($this->revisionRunItemId);
        if (!$item) {
            return;
        }

        $isFacadePosition = $item->position?->kind === \App\Models\ProjectPosition::KIND_FACADE;
        $isFacadeMaterial = ($item->material?->type === \App\Models\Material::TYPE_FACADE)
            || ($item->position?->facadeMaterial?->type === \App\Models\Material::TYPE_FACADE);
        if ($isFacadePosition || $isFacadeMaterial) {
            $item->update([
                'status' => RevisionRunItem::STATUS_OK,
                'message' => 'Фасады исключены из автосбора обоснований',
            ]);
            $this->refreshRunStats($item->run);
            return;
        }

        $material = $item->material
            ?: $item->projectFitting?->material
            ?: $item->position?->facadeMaterial
            ?: $item->position?->edgeMaterial
            ?: $item->position?->material;
        if (!$material) {
            $this->markNeedsManual($item, RevisionRunItem::STATUS_PARSE_ERROR, 'Материал позиции не найден');
            return;
        }

        $rawUrl = (string) ($material->source_url ?: $item->source_url);
        $normalizedUrl = $urlNormalizer->normalize($rawUrl);
        if (!$normalizedUrl) {
            $this->markNeedsManual($item, RevisionRunItem::STATUS_NO_TEMPLATE, 'Отсутствует URL источника');
            return;
        }

        $regionId = $item->run?->project?->region_id;

        // Если сегодня уже есть актуальный снимок — переиспользовать, не парсить заново
        $todayHistory = MaterialPriceHistory::where('normalized_source_url', $normalizedUrl)
            ->where('region_id', $regionId)
            ->whereNotNull('screenshot_path')
            ->whereDate('created_at', today())
            ->latest('id')
            ->first();

        if ($todayHistory) {
            $item->update([
                'status' => RevisionRunItem::STATUS_OK,
                'message' => 'Использован актуальный снимок за сегодня',
                'source_url' => $normalizedUrl,
                'material_id' => $material->id,
                'price_history_id' => $todayHistory->id,
            ]);
            $this->refreshRunStats($item->run);
            return;
        }
        $parseResult = $parseService->parseByUrl($rawUrl, (string) $material->type, $regionId);
        $parsedPrice = (float) ($parseResult['data']['price_per_unit'] ?? 0);
        $parsedName = trim((string) ($parseResult['data']['name'] ?? $material->name));
        $parseStatus = (string) ($parseResult['parse_status'] ?? 'error');

        if ($parsedPrice <= 0) {
            if ($parseStatus === 'blocked') {
                $this->markNeedsManual($item, RevisionRunItem::STATUS_BLOCKED, 'Источник заблокирован');
                return;
            }

            // Price not found — attempt screenshot anyway for OK_NO_PRICE
            $shotNoPrice = $captureService->captureByUrl(
                url: $rawUrl,
                price: 0,
                currency: 'RUB',
                regionId: $regionId,
                materialId: $material->id,
                revisionRunItemId: $item->id
            );

            $shotNoPriceStatus = (string) ($shotNoPrice['status'] ?? 'error');
            if ($shotNoPriceStatus === 'ok' && !empty($shotNoPrice['screenshot_path'])) {
                $history = MaterialPriceHistory::create([
                    'material_id' => $material->id,
                    'version' => (int) ($material->version ?? 1),
                    'valid_from' => now()->toDateString(),
                    'price_per_unit' => 0,
                    'source_url' => $normalizedUrl,
                    'raw_source_url' => $rawUrl,
                    'normalized_source_url' => $normalizedUrl,
                    'screenshot_path' => $shotNoPrice['screenshot_path'],
                    'observed_at' => now(),
                    'region_id' => $regionId,
                    'source_type' => MaterialPriceHistory::SOURCE_WEB,
                    'is_verified' => false,
                    'true_score' => 0,
                    'currency' => 'RUB',
                ]);

                $item->update([
                    'status' => RevisionRunItem::STATUS_OK_NO_PRICE,
                    'message' => 'Скриншот получен, но цена не извлечена',
                    'source_url' => $normalizedUrl,
                    'material_id' => $material->id,
                    'price_history_id' => $history->id,
                ]);
                $this->refreshRunStats($item->run, true);
                return;
            }

            $this->markNeedsManual($item, RevisionRunItem::STATUS_PARSE_ERROR, 'Цена не извлечена');
            return;
        }

        $existing = MaterialPriceHistory::where('normalized_source_url', $normalizedUrl)
            ->where('price_per_unit', $parsedPrice)
            ->where('currency', 'RUB')
            ->where('region_id', $regionId)
            ->whereNotNull('screenshot_path')
            ->latest('id')
            ->first();

        if ($existing) {
            $item->update([
                'status' => RevisionRunItem::STATUS_OK,
                'message' => 'Переиспользован существующий snapshot',
                'source_url' => $normalizedUrl,
                'material_id' => $material->id,
                'price_history_id' => $existing->id,
            ]);
            $this->refreshRunStats($item->run);
            return;
        }

        $shot = $captureService->captureByUrl(
            url: $rawUrl,
            price: $parsedPrice,
            currency: 'RUB',
            regionId: $regionId,
            materialId: $material->id,
            revisionRunItemId: $item->id
        );

        $shotStatus = (string) ($shot['status'] ?? 'error');
        if ($shotStatus !== 'ok') {
            $mapped = match ($shotStatus) {
                'blocked' => RevisionRunItem::STATUS_BLOCKED,
                'timeout' => RevisionRunItem::STATUS_TIMEOUT,
                default => RevisionRunItem::STATUS_PARSE_ERROR,
            };
            $this->markNeedsManual($item, $mapped, 'Не удалось получить скриншот');
            return;
        }

        // Если адаптер вернул цену со страницы — используем её, чтобы цена в PDF совпадала со скриншотом
        $adapterPrice = isset($shot['meta']['extracted_price'])
            ? (float) $shot['meta']['extracted_price']
            : null;
        $finalPrice = ($adapterPrice && $adapterPrice > 0) ? $adapterPrice : $parsedPrice;

        $history = MaterialPriceHistory::create([
            'material_id' => $material->id,
            'version' => (int) ($material->version ?? 1),
            'valid_from' => now()->toDateString(),
            'price_per_unit' => $finalPrice,
            'source_url' => $normalizedUrl,
            'raw_source_url' => $rawUrl,
            'normalized_source_url' => $normalizedUrl,
            'screenshot_path' => $shot['screenshot_path'],
            'observed_at' => now(),
            'region_id' => $regionId,
            'source_type' => MaterialPriceHistory::SOURCE_WEB,
            'is_verified' => true,
            'true_score' => 100,
            'currency' => 'RUB',
        ]);

        $item->update([
            'status' => RevisionRunItem::STATUS_OK,
            'message' => 'Snapshot обновлен автоматически',
            'source_url' => $normalizedUrl,
            'material_id' => $material->id,
            'price_history_id' => $history->id,
        ]);

        $this->refreshRunStats($item->run);
    }

    private function markNeedsManual(RevisionRunItem $item, string $status, string $message): void
    {
        $item->update([
            'status' => $status,
            'message' => $message,
        ]);
        $this->refreshRunStats($item->run, true);
    }

    private function refreshRunStats(?RevisionRun $run, bool $markNeedsManual = false): void
    {
        if (!$run) {
            return;
        }

        $total = $run->items()->count();
        $ok = $run->items()->whereIn('status', [
            RevisionRunItem::STATUS_OK,
            RevisionRunItem::STATUS_OK_NO_PRICE,
        ])->count();
        // Count only actual failures, not pending (unprocessed) items
        $failed = $run->items()->whereNotIn('status', [
            RevisionRunItem::STATUS_OK,
            RevisionRunItem::STATUS_OK_NO_PRICE,
            RevisionRunItem::STATUS_PENDING,
        ])->count();

        // Do not change status from IN_PROGRESS mid-processing.
        // The final status is set explicitly by RunRevisionUpdateJob::handle().
        $newStatus = $run->status;
        if ($run->status !== RevisionRun::STATUS_IN_PROGRESS) {
            if ($markNeedsManual || $failed > 0) {
                $newStatus = RevisionRun::STATUS_NEEDS_MANUAL;
            } elseif ($total > 0 && $ok === $total) {
                $newStatus = RevisionRun::STATUS_READY;
            }
        }

        $run->update([
            'status' => $newStatus,
            'total_items' => $total,
            'ok_items' => $ok,
            'failed_items' => $failed,
        ]);
    }
}
