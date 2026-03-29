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
    ) {}

    /**
     * Create a standalone EvidenceRecord (not tied to any run item).
     */
    public function captureObservation(array $payload, int $userId, ?UploadedFile $screenshot = null): array
    {
        $normalized = $this->urlNormalizer->normalize($payload['source_url'] ?? null);
        $domain = $normalized ? (parse_url($normalized, PHP_URL_HOST) ?: null) : null;

        $duplicate = $this->findDuplicate($payload, $userId);
        if ($duplicate) {
            return [
                'record'     => $duplicate,
                'asset'      => null,
                'duplicate'  => true,
            ];
        }

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
            'record'    => $record,
            'asset'     => $asset,
            'duplicate' => false,
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
}
