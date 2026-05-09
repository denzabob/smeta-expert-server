<?php

namespace App\Services\Reports;

use App\Models\Project;
use App\Models\UserSettings;

class ReportSettingsResolver
{
    private const SHORT_TEXT_MAX = 255;
    private const LONG_TEXT_MAX = 2000;

    /**
     * User-editable report text defaults.
     *
     * Product branding and authenticity blocks are intentionally not part of
     * this structure: regular report settings must not hide or replace them.
     */
    public static function defaults(): array
    {
        return [
            'common' => [
                'project_label' => 'Проект (дело)',
                'object_label' => 'Объект',
                'executor_label' => 'Эксперт',
                'signature_label' => 'Подпись',
                'date_label' => 'Дата',
            ],
            'estimate_report' => [
                'appendix_label' => 'Приложение № 1',
                'document_context_label' => 'к экспертному заключению',
                'title' => 'Расчёт стоимости материалов и работ',
                'calculation_date_label' => 'Дата расчёта',
                'summary_title' => 'Сводные итоги',
                'materials_summary_label' => 'Материалы (плиты + кромки)',
                'operations_summary_label' => 'Операции',
                'fittings_summary_label' => 'Фурнитура/комплектующие',
                'labor_summary_label' => 'Монтажно-демонтажные работы',
                'expenses_summary_label' => 'Накладные расходы',
                'final_total_label' => 'ИТОГО',
                'amount_in_words_label' => 'Прописью',
                'details_section_title' => 'Перечень деталей, принятых к расчёту',
                'plate_materials_section_title' => 'Расчёт плитных материалов',
                'edge_materials_section_title' => 'Расчёт кромочного материала',
                'final_cost_section_title' => 'Итоговая стоимость',
                'show_methodology_section' => true,
            ],
            'price_evidence_report' => [
                'title' => 'Документ подтверждения цен',
                'subtitle' => 'Источники, скриншоты и файлы, подтверждающие стоимость позиций сметы.',
                'project_label' => 'Проект',
                'report_version_label' => 'Версия отчета',
                'report_created_at_label' => 'Дата формирования отчета',
                'total_items_label' => 'Всего позиций',
                'confirmed_items_label' => 'Подтверждено',
                'missing_items_label' => 'Без подтверждения',
                'fixation_period_label' => 'Период фиксации цен',
                'missing_evidence_section_title' => 'Позиции без подтверждения цены',
                'internal_calculation_section_title' => 'Позиции, рассчитанные внутренним способом',
                'materials_evidence_section_title' => 'Материалы и ценовые подтверждения',
                'internal_calculation_basis_text' => 'внутренний расчет; скриншот не требуется',
            ],
            'evidence_reasons' => [
                'no_linked_evidence' => 'нет связанного подтверждения цены',
                'no_screenshot_or_document' => 'нет скриншота или документа',
                'outdated_price_confirmation' => 'подтверждение цены устарело',
                'no_source_url' => 'нет ссылки на источник цены',
                'internal_calculation_no_screenshot_required' => 'внутренний расчет; скриншот не требуется',
            ],
        ];
    }

    public function validationRules(string $prefix = 'report_settings'): array
    {
        $rules = [
            $prefix => ['nullable', 'array'],
            "{$prefix}.common" => ['nullable', 'array'],
            "{$prefix}.estimate_report" => ['nullable', 'array'],
            "{$prefix}.price_evidence_report" => ['nullable', 'array'],
            "{$prefix}.evidence_reasons" => ['nullable', 'array'],
        ];

        foreach ($this->shortTextPaths() as $path) {
            $rules["{$prefix}.{$path}"] = ['nullable', 'string', 'max:' . self::SHORT_TEXT_MAX];
        }

        foreach ($this->longTextPaths() as $path) {
            $rules["{$prefix}.{$path}"] = ['nullable', 'string', 'max:' . self::LONG_TEXT_MAX];
        }

        foreach ($this->booleanPaths() as $path) {
            $rules["{$prefix}.{$path}"] = ['nullable', 'boolean'];
        }

        return $rules;
    }

    public function normalize(?array $settings): array
    {
        return $this->merge($settings);
    }

    public function merge(?array ...$layers): array
    {
        $merged = self::defaults();

        foreach ($layers as $layer) {
            if (!is_array($layer) || $layer === []) {
                continue;
            }

            $merged = array_replace_recursive($merged, $this->sanitize($layer));
        }

        return $merged;
    }

    public function forProject(Project $project): array
    {
        $projectSettings = is_array($project->report_settings ?? null) ? $project->report_settings : null;
        if ($projectSettings) {
            return $this->normalize($projectSettings);
        }

        $project->loadMissing('user.settings');
        $userSettings = $project->user?->settings;
        if ($userSettings instanceof UserSettings && is_array($userSettings->report_settings ?? null)) {
            return $this->normalize($userSettings->report_settings);
        }

        return $this->normalize(null);
    }

    public function forSnapshot(array $snapshot, ?Project $project = null): array
    {
        if (is_array($snapshot['report_settings'] ?? null)) {
            return $this->normalize($snapshot['report_settings']);
        }

        if (is_array(data_get($snapshot, 'project.report_settings'))) {
            return $this->normalize(data_get($snapshot, 'project.report_settings'));
        }

        return $project ? $this->forProject($project) : $this->normalize(null);
    }

    private function sanitize(array $settings): array
    {
        $clean = [];

        foreach ($this->shortTextPaths() as $path) {
            $value = data_get($settings, $path);
            if (is_scalar($value)) {
                $value = trim((string) $value);
                if ($value !== '') {
                    data_set($clean, $path, mb_substr($value, 0, self::SHORT_TEXT_MAX));
                }
            }
        }

        foreach ($this->longTextPaths() as $path) {
            $value = data_get($settings, $path);
            if (is_scalar($value)) {
                $value = trim((string) $value);
                if ($value !== '') {
                    data_set($clean, $path, mb_substr($value, 0, self::LONG_TEXT_MAX));
                }
            }
        }

        foreach ($this->booleanPaths() as $path) {
            $value = $this->normalizeBoolean(data_get($settings, $path));
            if ($value !== null) {
                data_set($clean, $path, $value);
            }
        }

        return $clean;
    }

    private function normalizeBoolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === 1 || $value === 0) {
            return (bool) $value;
        }

        if (is_string($value)) {
            $normalized = mb_strtolower(trim($value));
            return match ($normalized) {
                '1', 'true', 'yes', 'on' => true,
                '0', 'false', 'no', 'off' => false,
                default => null,
            };
        }

        return null;
    }

    private function shortTextPaths(): array
    {
        return [
            'common.project_label',
            'common.object_label',
            'common.executor_label',
            'common.signature_label',
            'common.date_label',
            'estimate_report.appendix_label',
            'estimate_report.document_context_label',
            'estimate_report.title',
            'estimate_report.calculation_date_label',
            'estimate_report.summary_title',
            'estimate_report.materials_summary_label',
            'estimate_report.operations_summary_label',
            'estimate_report.fittings_summary_label',
            'estimate_report.labor_summary_label',
            'estimate_report.expenses_summary_label',
            'estimate_report.final_total_label',
            'estimate_report.amount_in_words_label',
            'estimate_report.details_section_title',
            'estimate_report.plate_materials_section_title',
            'estimate_report.edge_materials_section_title',
            'estimate_report.final_cost_section_title',
            'price_evidence_report.title',
            'price_evidence_report.project_label',
            'price_evidence_report.report_version_label',
            'price_evidence_report.report_created_at_label',
            'price_evidence_report.total_items_label',
            'price_evidence_report.confirmed_items_label',
            'price_evidence_report.missing_items_label',
            'price_evidence_report.fixation_period_label',
            'price_evidence_report.missing_evidence_section_title',
            'price_evidence_report.internal_calculation_section_title',
            'price_evidence_report.materials_evidence_section_title',
            'evidence_reasons.no_linked_evidence',
            'evidence_reasons.no_screenshot_or_document',
            'evidence_reasons.outdated_price_confirmation',
            'evidence_reasons.no_source_url',
            'evidence_reasons.internal_calculation_no_screenshot_required',
        ];
    }

    private function longTextPaths(): array
    {
        return [
            'price_evidence_report.subtitle',
            'price_evidence_report.internal_calculation_basis_text',
        ];
    }

    private function booleanPaths(): array
    {
        return [
            'estimate_report.show_methodology_section',
        ];
    }
}
