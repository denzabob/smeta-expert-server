<?php

namespace App\Services;

use App\Evidence\CaptureMethod;
use App\Evidence\CostComponent;
use App\Evidence\EvidenceItemStatus;
use App\Evidence\EvidenceRunStatus;
use App\Evidence\ResolutionType;
use App\Evidence\SourceType;
use App\Evidence\VerificationStatus;
use App\Models\EstimateEvidenceItem;
use App\Models\EstimateEvidenceRun;
use App\Models\EvidenceLink;
use App\Models\EvidenceRecord;
use App\Models\GenericEvidenceAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GenericChromeCaptureService
{
    public function __construct(
        private UrlNormalizer $urlNormalizer,
        private MaterialConfirmationService $confirmationService,
    ) {}

    /**
     * Create a standalone EvidenceRecord (not tied to any run item).
     *
     * Deduplication hierarchy:
     *  1. Rapid duplicate: same URL+component within 60 s → reuse (duplicate=true)
     *  2. Fresh equivalent: same URL+component+price within freshness window,
     *     has a screenshot asset → reuse (fresh_reuse=true)
     *  3. Otherwise: create a new record + screenshot
     */
    public function captureObservation(array $payload, int $userId, ?UploadedFile $screenshot = null): array
    {
        $normalized = $this->urlNormalizer->normalize($payload['source_url'] ?? null);
        $domain = $normalized ? (parse_url($normalized, PHP_URL_HOST) ?: null) : null;

        // 1. Rapid 60-second duplicate (unchanged)
        $duplicate = $this->findDuplicate($payload, $userId);
        if ($duplicate) {
            return [
                'record'      => $duplicate,
                'asset'       => null,
                'duplicate'   => true,
                'fresh_reuse' => false,
            ];
        }

        // 2. Fresh equivalent proof — same URL, component, price (±1%), with screenshot
        $freshEquivalent = $this->confirmationService->getFreshEquivalentRecord(
            $payload['source_url'] ?? null,
            $payload['cost_component'],
            $payload['observed_price'] ?? null,
        );
        if ($freshEquivalent) {
            return [
                'record'      => $freshEquivalent,
                'asset'       => null,
                'duplicate'   => false,
                'fresh_reuse' => true,
            ];
        }

        // 3. Create new record
        $record = EvidenceRecord::create([
            'uuid'                => (string) Str::uuid(),
            'cost_component'      => $payload['cost_component'],
            'source_type'         => SourceType::CHROME_CAPTURE,
            'capture_method'      => CaptureMethod::CHROME_EXTENSION,
            'verification_status' => VerificationStatus::PENDING,
            'source_url'          => $normalized,
            'source_domain'       => $domain,
            'observed_price'      => $payload['observed_price'] ?? null,
            'currency'            => isset($payload['currency']) ? strtoupper($payload['currency']) : null,
            'observed_at'         => now(),
            'extracted_name'      => $payload['extracted_name'] ?? null,
            'extracted_article'   => $payload['extracted_article'] ?? null,
            'metadata_json'       => $this->buildMetadataJson($payload),
            'confidence_score'    => $payload['confidence_score'] ?? null,
            'trust_score'         => 60,
            'created_by'          => $userId,
        ]);

        $asset = null;
        if ($screenshot) {
            $asset = $this->storeScreenshot($record, $screenshot);
        }

        return [
            'record'      => $record,
            'asset'       => $asset,
            'duplicate'   => false,
            'fresh_reuse' => false,
        ];
    }

    /**
     * Capture evidence for a specific run item: create record, link, resolve item, refresh counters.
     */
    public function captureForItem(EstimateEvidenceItem $item, array $payload, int $userId, ?UploadedFile $screenshot = null): array
    {
        if (in_array($item->status, EvidenceItemStatus::terminalStatuses(), true)) {
            return [
                'error'   => "Item already in terminal status '{$item->status}'.",
                'success' => false,
            ];
        }

        $run = $item->run;
        if (in_array($run->status, EvidenceRunStatus::terminalStatuses(), true)) {
            return [
                'error'   => "Cannot modify items in a {$run->status} run.",
                'success' => false,
            ];
        }

        // Force cost_component from the item to ensure consistency
        $payload['cost_component'] = $item->cost_component;

        $duplicate = $this->findDuplicateForItem($payload, $item->id);
        if ($duplicate) {
            return [
                'record'    => $duplicate,
                'asset'     => null,
                'duplicate' => true,
                'success'   => true,
            ];
        }

        return DB::transaction(function () use ($item, $payload, $userId, $screenshot, $run) {
            $result = $this->captureObservation($payload, $userId, $screenshot);
            $record = $result['record'];

            // Link record to evidence item
            EvidenceLink::create([
                'evidence_record_id' => $record->id,
                'linkable_type'      => EstimateEvidenceItem::class,
                'linkable_id'        => $item->id,
                'relation_type'      => 'captured_for',
            ]);

            // Resolve the item
            $item->update([
                'status'             => EvidenceItemStatus::RESOLVED,
                'resolution_type'    => ResolutionType::CHROME,
                'evidence_record_id' => $record->id,
                'source_url'         => $item->source_url ?: $record->source_url,
                'effective_value'    => $record->observed_price ?? $item->effective_value,
                'currency'           => $record->currency ?? $item->currency,
            ]);

            $this->refreshRunCounters($run);

            return [
                'record'    => $record,
                'asset'     => $result['asset'],
                'duplicate' => false,
                'success'   => true,
            ];
        });
    }

    /**
     * Find a duplicate record: same source_url + cost_component created within 60 seconds.
     */
    public function findDuplicate(array $payload, int $userId): ?EvidenceRecord
    {
        $normalized = $this->urlNormalizer->normalize($payload['source_url'] ?? null);
        if (!$normalized) {
            return null;
        }

        return EvidenceRecord::where('source_url', $normalized)
            ->where('cost_component', $payload['cost_component'])
            ->where('capture_method', CaptureMethod::CHROME_EXTENSION)
            ->where('created_by', $userId)
            ->where('created_at', '>=', now()->subSeconds(60))
            ->first();
    }

    /**
     * Find a duplicate record for a specific item context.
     */
    public function findDuplicateForItem(array $payload, int $itemId): ?EvidenceRecord
    {
        $normalized = $this->urlNormalizer->normalize($payload['source_url'] ?? null);
        if (!$normalized) {
            return null;
        }

        return EvidenceRecord::where('source_url', $normalized)
            ->where('cost_component', $payload['cost_component'])
            ->where('capture_method', CaptureMethod::CHROME_EXTENSION)
            ->where('created_at', '>=', now()->subSeconds(60))
            ->whereHas('links', function ($q) use ($itemId) {
                $q->where('linkable_type', EstimateEvidenceItem::class)
                  ->where('linkable_id', $itemId);
            })
            ->first();
    }

    /**
     * Store a screenshot as a GenericEvidenceAsset, with sha256-based dedup within the record.
     */
    public function storeScreenshot(EvidenceRecord $record, UploadedFile $file): GenericEvidenceAsset
    {
        $sha256 = hash_file('sha256', $file->getRealPath());

        // Dedup: same sha256 within same record
        $existing = GenericEvidenceAsset::where('evidence_record_id', $record->id)
            ->where('sha256', $sha256)
            ->first();

        if ($existing) {
            return $existing;
        }

        $path = $file->store('screenshots/chrome/generic/' . now()->format('Y/m'), 'public');

        return GenericEvidenceAsset::create([
            'uuid'               => (string) Str::uuid(),
            'evidence_record_id' => $record->id,
            'asset_type'         => 'screenshot',
            'file_path'          => $path,
            'original_filename'  => $file->getClientOriginalName(),
            'mime_type'          => $file->getMimeType(),
            'file_size'          => $file->getSize(),
            'sha256'             => $sha256,
        ]);
    }

    /**
     * Build metadata_json from extra capture payload fields.
     */
    public function buildMetadataJson(array $payload): ?array
    {
        $keys = [
            'capture_mode',
            'field_sources_json',
            'selectors_json',
            'browser_context_json',
            'annotation_map_json',
            'template_id',
        ];

        $meta = [];
        foreach ($keys as $key) {
            if (isset($payload[$key])) {
                $meta[$key] = $payload[$key];
            }
        }

        return !empty($meta) ? $meta : null;
    }

    /**
     * Recalculate run counters and auto-transition to READY when all items are terminal.
     */
    private function refreshRunCounters(EstimateEvidenceRun $run): void
    {
        $items = $run->items()->get();

        $completed = $items->filter(
            fn ($i) => in_array($i->status, EvidenceItemStatus::completedStatuses(), true)
        )->count();

        $failed = $items->where('status', EvidenceItemStatus::FAILED)->count();

        $allTerminal = $items->every(
            fn ($i) => in_array($i->status, EvidenceItemStatus::terminalStatuses(), true)
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
     * Attempt deterministic auto-link of an evidence record to an unresolved evidence item.
     *
     * Directly links the existing record (which already has the screenshot asset)
     * to the matching item instead of creating a new record through captureForItem.
     *
     * Rules (strict — no fuzzy matching):
     *  1. Item must belong to user's non-terminal run
     *  2. Item must not be in a terminal status
     *  3. Item cost_component must exactly match the derived cost component
     *  4. Item source_url must match the browser URL after normalizing BOTH sides
     *     with the same UrlNormalizer (handles cleanUrl/parser/raw format differences)
     *  5. Exactly ONE candidate must exist — 0 or 2+ means no auto-link
     *
     * @return array{linked: bool, item_id: ?int, item_label: ?string}
     */
    public function autoLinkToEvidenceItem(
        EvidenceRecord $record,
        int $userId,
        string $costComponent,
        string $sourceUrl,
    ): array {
        $normalizedUrl = $this->urlNormalizer->normalize($sourceUrl);

        if (!$normalizedUrl) {
            return ['linked' => false, 'item_id' => null, 'item_label' => null];
        }

        // Fetch candidates by user scope + cost component + non-terminal status.
        // URL matching is done in PHP with normalization on BOTH sides to handle
        // inconsistencies between how item source_url was stored (cleanUrl, parser,
        // raw) vs how the browser URL is normalized.  The candidate set is small:
        // bounded by pending items in a user's active runs for one cost component.
        $candidates = EstimateEvidenceItem::whereHas('run', function ($q) use ($userId) {
                $q->where('initiated_by', $userId)
                  ->whereNotIn('status', EvidenceRunStatus::terminalStatuses());
            })
            ->whereNotIn('status', EvidenceItemStatus::terminalStatuses())
            ->where('cost_component', $costComponent)
            ->whereNotNull('source_url')
            ->with('run')
            ->get()
            ->filter(fn ($item) => $this->urlNormalizer->normalize($item->source_url) === $normalizedUrl)
            ->values();

        if ($candidates->count() !== 1) {
            return ['linked' => false, 'item_id' => null, 'item_label' => null];
        }

        $item = $candidates->first();
        $run = $item->run;

        if (in_array($run->status, EvidenceRunStatus::terminalStatuses(), true)) {
            return ['linked' => false, 'item_id' => null, 'item_label' => null];
        }

        try {
            DB::transaction(function () use ($record, $item, $run) {
                // Link the existing record (with its screenshot asset) directly
                EvidenceLink::create([
                    'evidence_record_id' => $record->id,
                    'linkable_type'      => EstimateEvidenceItem::class,
                    'linkable_id'        => $item->id,
                    'relation_type'      => 'auto_linked',
                ]);

                $item->update([
                    'status'             => EvidenceItemStatus::RESOLVED,
                    'resolution_type'    => ResolutionType::CHROME,
                    'evidence_record_id' => $record->id,
                    'source_url'         => $item->source_url ?: $record->source_url,
                    'effective_value'    => $record->observed_price ?? $item->effective_value,
                    'currency'           => $record->currency ?? $item->currency,
                ]);

                $this->refreshRunCounters($run);
            });
        } catch (\Throwable) {
            return ['linked' => false, 'item_id' => null, 'item_label' => null];
        }

        return [
            'linked'     => true,
            'item_id'    => $item->id,
            'item_label' => $item->label,
        ];
    }
}
