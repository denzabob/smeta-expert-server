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
    public function __construct(
        private FinishedProductFacadeSnapshotPresenter $finishedProductFacadeSnapshotPresenter,
    ) {}

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
                ->values();

            if ($entries->isEmpty()) {
                continue;
            }

            if ($sectionKey === 'labor') {
                $internalEntries = $entries
                    ->filter(fn ($entry) => ($entry['labor_entry_kind'] ?? 'internal') !== 'external')
                    ->values()
                    ->toArray();
                $externalEntries = $entries
                    ->filter(fn ($entry) => ($entry['labor_entry_kind'] ?? null) === 'external')
                    ->values()
                    ->toArray();

                $rateDisplay = null;
                foreach ($internalEntries as $entry) {
                    if (!empty($entry['accepted_display'])) {
                        $rateDisplay = $entry['accepted_display'];
                        break;
                    }
                }

                $sections[] = [
                    'title' => $sectionDef['label'],
                    'section_type' => $sectionKey,
                    'is_internal' => empty($externalEntries) && !empty($internalEntries),
                    'rate_display' => $rateDisplay,
                    'entries' => $entries->toArray(),
                    'internal_entries' => $internalEntries,
                    'external_entries' => $externalEntries,
                ];

                continue;
            }

            $entriesArray = $entries->toArray();
            $isInternal = collect($entriesArray)->every(fn($e) => !$e['is_external']);

            $sections[] = [
                'title'        => $sectionDef['label'],
                'section_type' => $sectionKey,
                'is_internal'  => $isInternal,
                'rate_display' => null,
                'entries'      => $entriesArray,
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
        $facadeSnapshotSummary = is_array($item['diagnostics_json']['facade_snapshot_summary'] ?? null)
            ? $item['diagnostics_json']['facade_snapshot_summary']
            : null;
        $facadeSourceLevelSnapshot = is_array($item['diagnostics_json']['facade_source_level_snapshot'] ?? null)
            ? $item['diagnostics_json']['facade_source_level_snapshot']
            : null;
        $facadeSnapshotPresentation = $facadeSnapshotSummary !== null
            ? $this->finishedProductFacadeSnapshotPresenter->presentFromJustificationSummary([
                ...$facadeSnapshotSummary,
                'source_level_snapshot' => $facadeSourceLevelSnapshot,
            ])
            : null;

        // Determine whether this is an internally-calculated position.
        $laborEntryKind = $item['diagnostics_json']['labor_entry_kind'] ?? null;
        $isLaborExternal = $costComponent === 'labor_work' && $laborEntryKind === 'external';

        $isInternal = !$isLaborExternal && (
            in_array($costComponent, self::INTERNAL_COMPONENTS, true)
            || ($record !== null && ($record['source_type'] ?? '') === 'internal_calc')
        );

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
        if ($facadeSnapshotSummary !== null) {
            $attachmentMode = 'summary';
            $attachmentCaption = 'Для этой фасадной позиции зафиксирован summary-level pricing snapshot без source-level графических вложений.';
        } elseif ($isInternal) {
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
        if ($facadeSnapshotSummary !== null) {
            $confirmationNote = 'Стоимость фасада подтверждена зафиксированным pricing snapshot позиции и не реконструируется из legacy evidence fields.';
        } elseif ($isInternal) {
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
            'labor_entry_kind'   => $laborEntryKind,
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
            'provider_title'     => $item['diagnostics_json']['provider_title'] ?? null,
            'provider_domain'    => $item['diagnostics_json']['provider_domain'] ?? null,
            'employer_name'      => $item['diagnostics_json']['employer_name'] ?? null,
            'vacancy_title'      => $item['diagnostics_json']['vacancy_title'] ?? null,
            'vacancy_description' => $item['diagnostics_json']['vacancy_description'] ?? null,
            'vacancy_excerpt'    => $item['diagnostics_json']['vacancy_excerpt'] ?? null,
            'salary_raw_text'    => $item['diagnostics_json']['salary_raw_text'] ?? null,
            'salary_value'       => $item['diagnostics_json']['salary_value'] ?? null,
            'salary_value_min'   => $item['diagnostics_json']['salary_value_min'] ?? null,
            'salary_value_max'   => $item['diagnostics_json']['salary_value_max'] ?? null,
            'salary_period'      => $item['diagnostics_json']['salary_period'] ?? null,
            'hours_per_month'    => $item['diagnostics_json']['hours_per_month'] ?? null,
            'hourly_rate_display' => $this->formatMoney($item['diagnostics_json']['derived_hourly_rate'] ?? $effectiveValue),
            'salary_display'     => $this->formatSalary(
                $item['diagnostics_json']['salary_raw_text'] ?? null,
                $item['diagnostics_json']['salary_value'] ?? null,
                $item['diagnostics_json']['salary_value_min'] ?? null,
                $item['diagnostics_json']['salary_value_max'] ?? null,
                $item['diagnostics_json']['salary_period'] ?? null,
            ),
            'region_name'        => $item['diagnostics_json']['region_name'] ?? null,
            'labor_note'         => $item['diagnostics_json']['note'] ?? null,
            'source_title'       => $item['diagnostics_json']['source_title'] ?? null,
            'source_date_display' => $this->formatDate($item['diagnostics_json']['source_date'] ?? null),
            'is_snapshot_summary' => $facadeSnapshotSummary !== null,
            'snapshot_summary' => $facadeSnapshotSummary,
            'source_level_snapshot' => $facadeSourceLevelSnapshot,
            'facade_snapshot_presentation' => $facadeSnapshotPresentation,
        ];
    }

    private function formatSalary(
        mixed $salaryRawText,
        mixed $salaryValue,
        mixed $salaryValueMin,
        mixed $salaryValueMax,
        mixed $salaryPeriod,
    ): ?string {
        if (is_string($salaryRawText) && trim($salaryRawText) !== '') {
            return trim($salaryRawText);
        }

        $periodLabel = match ($salaryPeriod) {
            'hour' => 'в час',
            'day' => 'в день',
            'month' => 'в месяц',
            'year' => 'в год',
            'project' => 'за проект',
            default => null,
        };

        if ($salaryValue !== null && $salaryValue !== '') {
            return trim(($this->formatMoney($salaryValue) ?? '') . ($periodLabel ? ' ' . $periodLabel : ''));
        }

        if ($salaryValueMin !== null || $salaryValueMax !== null) {
            $parts = [];
            if ($salaryValueMin !== null && $salaryValueMin !== '') {
                $parts[] = 'от ' . $this->formatMoney($salaryValueMin);
            }
            if ($salaryValueMax !== null && $salaryValueMax !== '') {
                $parts[] = 'до ' . $this->formatMoney($salaryValueMax);
            }

            return trim(implode(' ', $parts) . ($periodLabel ? ' ' . $periodLabel : ''));
        }

        return null;
    }

    private function formatDate(?string $date): ?string
    {
        if (!$date) {
            return null;
        }

        try {
            return Carbon::parse($date)->format('d.m.Y');
        } catch (\Throwable) {
            return null;
        }
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
