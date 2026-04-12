<?php

namespace App\Services;

use App\Models\EstimateEvidenceRun;
use App\Models\Project;
use Carbon\Carbon;

/**
 * Builds a human-readable, legally neutral view model from a finalized
 * evidence run's snapshot_json. The output is consumed by the
 * evidence_run Blade template via DomPDF.
 *
 * Design principles:
 *  - No raw enum names, UUIDs, technical statuses reach the template.
 *  - All field names are user-facing and legally neutral.
 *  - Template logic is minimised: all classification is done here.
 *  - Document structure matches the smeta section order.
 */
class EstimateEvidencePdfBuilder
{
    /**
     * Section definitions in smeta order.
     * Each section lists the cost_component values it covers.
     */
    private const SECTIONS = [
        'materials'  => [
            'label'      => 'Раздел 1. Материалы',
            'components' => ['plate', 'edge', 'facade', 'fitting'],
        ],
        'operations' => [
            'label'      => 'Раздел 2. Производственные операции',
            'components' => ['operation'],
        ],
        'labor'      => [
            'label'      => 'Раздел 3. Монтажно-демонтажные работы',
            'components' => ['labor_work'],
        ],
        'expenses'   => [
            'label'      => 'Раздел 4. Накладные расходы',
            'components' => ['expense'],
        ],
    ];

    /**
     * Human-readable labels for cost components (used as "Вид позиции").
     */
    private const COMPONENT_LABELS = [
        'plate'      => 'Листовой материал',
        'edge'       => 'Кромка',
        'facade'     => 'Фасад',
        'fitting'    => 'Фурнитура',
        'operation'  => 'Производственная операция',
        'labor_work' => 'Монтажно-демонтажная работа',
        'expense'    => 'Накладные расходы',
    ];

    /**
     * Default unit of measure hint per cost component (shown in material cards).
     * Used when the evidence record does not carry an explicit unit field.
     */
    private const COMPONENT_UNITS = [
        'plate'   => 'м²',
        'edge'    => 'м.п.',
        'facade'  => 'шт.',
        'fitting' => 'шт.',
    ];

    /**
     * Cost components whose values are determined by internal calculation
     * rather than external price sources.
     */
    private const INTERNAL_COMPONENTS = ['operation', 'labor_work', 'expense'];

    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Build the view data array for the evidence run PDF template.
     *
     * @return array{cover: array, summary: array, sections: array, closing_notes: array}
     */
    public function build(EstimateEvidenceRun $run, Project $project): array
    {
        $snapshot   = $run->snapshot_json ?? [];
        $rawItems   = $snapshot['evidence_items'] ?? [];
        $rawRecords = $snapshot['evidence_records'] ?? [];

        // Index evidence records by id for O(1) lookup.
        $recordsById = collect($rawRecords)->keyBy('id');

        // ── Cover / title page ──────────────────────────────────────────────
        $cover = [
            'document_title'    => 'Подтверждение стоимости материалов, работ, операций и расходов, учтённых в расчёте',
            'document_subtitle' => 'Приложение к экспертному заключению',
            'document_intro'    => 'Настоящее приложение содержит материалы, подтверждающие значения,'
                . ' принятые при расчёте стоимости материалов, производственных операций,'
                . ' монтажно-демонтажных работ и накладных расходов в смете по делу.',
            'project_number'    => $project->number ?? '—',
            'project_name'      => $project->expert_name ?? $project->address ?? '',
            'object_address'    => $project->address ?? '',
            'date'              => $run->finalized_at?->format('d.m.Y') ?? now()->format('d.m.Y'),
        ];

        // ── Normalise all entries ────────────────────────────────────────────
        $allEntries = collect($rawItems)
            ->map(fn(array $item) => $this->normalizeEntry($item, $recordsById));

        // ── Summary (legally neutral counts) ────────────────────────────────
        $summary = [
            'total_items'        => $allEntries->count(),
            'external_confirmed' => $allEntries->where('is_external', true)->count(),
            'internal_calc'      => $allEntries->where('is_external', false)->count(),
            'with_images'        => $allEntries->where('attachment_mode', 'image')->count(),
        ];

        // ── Document sections ordered as in the smeta ────────────────────────
        $sections = [];
        foreach (self::SECTIONS as $sectionKey => $sectionDef) {
            $entries = $allEntries
                ->filter(fn($e) => in_array($e['cost_component'], $sectionDef['components'], true))
                ->values()
                ->toArray();

            if (empty($entries)) {
                continue;
            }

            $isInternal = collect($entries)->every(fn($e) => !$e['is_external']);

            // For labor sections find the common hourly rate (first non-null accepted_display).
            $rateDisplay = null;
            if ($sectionKey === 'labor' && $isInternal) {
                foreach ($entries as $e) {
                    if (!empty($e['accepted_display'])) {
                        $rateDisplay = $e['accepted_display'];
                        break;
                    }
                }
            }

            $sections[] = [
                'title'        => $sectionDef['label'],
                'section_type' => $sectionKey,
                'is_internal'  => $isInternal,
                'rate_display' => $rateDisplay,
                'entries'      => $entries,
            ];
        }

        // ── Closing notes ────────────────────────────────────────────────────
        $closing_notes = [];
        if ($allEntries->where('attachment_mode', 'none')->count() > 0) {
            $closing_notes[] = 'По отдельным позициям графическое подтверждение не прилагается.'
                . ' Значения по таким позициям соответствуют расчётной части сметы.';
        }
        if ($allEntries->where('is_external', false)->count() > 0) {
            $closing_notes[] = 'Значения производственных операций, монтажно-демонтажных работ'
                . ' и накладных расходов приняты по внутренним расчётным параметрам,'
                . ' используемым в смете. Подробное числовое обоснование приведено'
                . ' в соответствующих разделах расчётной части.';
        }

        return [
            'cover'         => $cover,
            'summary'       => $summary,
            'sections'      => $sections,
            'closing_notes' => $closing_notes,
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Transform one raw snapshot item into a template-ready, humanized entry.
     * No raw enum values or technical identifiers are passed through.
     */
    private function normalizeEntry(array $item, \Illuminate\Support\Collection $recordsById): array
    {
        $recordId      = $item['evidence_record_id'] ?? null;
        $record        = $recordId ? ($recordsById[$recordId] ?? null) : null;
        $costComponent = $item['cost_component'] ?? '';

        // Determine whether this is an internally-calculated position.
        $isInternal = in_array($costComponent, self::INTERNAL_COMPONENTS, true)
            || ($record !== null && ($record['source_type'] ?? '') === 'internal_calc');

        // ── Attachments ──────────────────────────────────────────────────────
        $assets     = $record['assets'] ?? [];
        $imageAsset = collect($assets)->first(
            fn($a) => str_starts_with($a['mime_type'] ?? '', 'image/')
        );
        $docAssets  = collect($assets)
            ->filter(fn($a) => !str_starts_with($a['mime_type'] ?? '', 'image/'))
            ->values()
            ->all();

        // Resolve image path and existence.
        $imagePath   = null;
        $imageExists = false;
        if ($imageAsset) {
            $imagePath   = $imageAsset['file_path'] ?? null;
            $imageExists = $imagePath && file_exists(storage_path('app/public/' . $imagePath));
        }

        // ── Attachment mode + caption ────────────────────────────────────────
        if ($isInternal) {
            $attachmentMode    = 'internal';
            $attachmentCaption = 'Графический материал не прилагается,'
                . ' поскольку значение принято по внутреннему расчёту';
        } elseif ($imageAsset && $imageExists) {
            $attachmentMode    = 'image';
            $attachmentCaption = 'Подтверждение представлено в виде скриншота страницы источника';
        } elseif (!empty($docAssets)) {
            $attachmentMode    = 'document';
            $attachmentCaption = 'Подтверждение представлено в виде загруженного документа';
        } else {
            $attachmentMode    = 'none';
            $attachmentCaption = 'Графический материал не прилагается';
        }

        // ── Confirmation note ────────────────────────────────────────────────
        if ($isInternal) {
            $confirmationNote = 'Стоимость принята по внутреннему расчёту';
        } elseif ($attachmentMode === 'image' || $attachmentMode === 'document') {
            $confirmationNote = 'Стоимость подтверждена материалами приложения';
        } else {
            $confirmationNote = 'Значение соответствует расчётной части сметы';
        }

        // ── Recalculation note (when price differs from accepted value) ──────
        $observedPrice  = $record ? ($record['observed_price'] ?? null) : null;
        $effectiveValue = $item['effective_value'] ?? null;
        $recalcNote     = null;
        if (!$isInternal
            && $observedPrice !== null
            && $effectiveValue !== null
            && (float) $observedPrice !== (float) $effectiveValue
        ) {
            $recalcNote = 'В источнике указана цена в ином расчётном формате.'
                . ' В расчёте принято значение, приведённое к единице измерения,'
                . ' используемой в смете.';
        }

        // ── Capture date ─────────────────────────────────────────────────────
        $captureDate = null;
        if (!empty($record['observed_at'])) {
            try {
                $captureDate = Carbon::parse($record['observed_at'])->format('d.m.Y');
            } catch (\Throwable) {
                // Leave null if unparseable.
            }
        }

        // ── Document assets (human-readable) ─────────────────────────────────
        $docAssetsHuman = array_map(fn($a) => [
            'type'     => $a['asset_type'] ?? 'документ',
            'filename' => $a['original_filename'] ?? null,
            'mime'     => $a['mime_type'] ?? null,
        ], $docAssets);

        return [
            'cost_component'     => $costComponent,
            'is_external'        => !$isInternal,
            'entry_title'        => $item['label'] ?? 'Позиция',
            'entry_kind_label'   => self::COMPONENT_LABELS[$costComponent] ?? '',
            'unit_hint'          => $isInternal ? null : (self::COMPONENT_UNITS[$costComponent] ?? null),
            'extracted_name'     => $record['extracted_name'] ?? null,
            'extracted_article'  => $record['extracted_article'] ?? null,
            'source_label'       => $record['source_domain'] ?? null,
            'source_url'         => $record['source_url'] ?? ($item['source_url'] ?? null),
            'price_in_source'    => $observedPrice,
            'accepted_value'     => $effectiveValue,
            'currency'           => $item['currency'] ?? ($record['currency'] ?? 'RUB'),
            'capture_date'       => $captureDate,
            'recalculation_note' => $recalcNote,
            'confirmation_note'  => $confirmationNote,
            'attachment_mode'    => $attachmentMode,
            'attachment_caption' => $attachmentCaption,
            'image_path'         => $imagePath,
            'image_exists'       => $imageExists,
            'doc_assets'         => $docAssetsHuman,
            'price_display'      => $this->formatMoney($observedPrice),
            'accepted_display'   => $this->formatMoney($effectiveValue !== null ? (float) $effectiveValue : null),
        ];
    }

    /**
     * Format a monetary amount as a human-readable Russian string.
     * Example: 2500.00 → "2\u{00A0}500,00\u{00A0}руб."
     * Uses actual non-breaking space characters (not escaped sequences).
     */
    private function formatMoney(float|int|string|null $amount): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }
        $formatted = number_format((float) $amount, 2, ',', "\u{00A0}");
        return $formatted . "\u{00A0}" . 'руб.';
    }
}
