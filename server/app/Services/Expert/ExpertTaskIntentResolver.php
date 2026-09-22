<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Models\Expert\ExpertConversation;

final class ExpertTaskIntentResolver
{
    public function __construct(private readonly ExpertSemanticTaskClassifier $semanticClassifier) {}

    /**
     * Resolve intent from a message and safe structural facts.
     *
     * @param  array<string, mixed>  $structuralFacts
     */
    public function resolve(string $message, array $structuralFacts = []): ExpertTaskIntent
    {
        $facts = $this->normaliseFacts($structuralFacts);
        $normalized = $this->normalize($message);
        $facts['message'] = $normalized;
        $deterministic = $this->deterministic($normalized, $facts);
        $signals = $deterministic['signals'];
        $source = 'deterministic';

        try {
            $semantic = $this->semanticClassifier->classify($message, $facts, $signals);
        } catch (\Throwable) {
            $semantic = null;
            $signals[] = 'semantic_classifier_unavailable';
        }

        if (is_array($semantic) && $this->semanticConfidence($semantic) >= 0.5) {
            $deterministic = $this->mergeSemantic($deterministic, $semantic, $facts);
            $source = 'deterministic+semantic';
            $signals[] = 'semantic_classifier';
        }

        $currentCount = (int) $facts['current_material_count'];
        $activeCount = (int) $facts['active_material_count'];
        $currentImages = $this->containsImage($facts['current_materials']);
        $selectedImages = $this->containsImage($facts['selected_materials']);
        $activeImages = $this->containsImage($facts['active_materials']);
        $intent = new ExpertTaskIntent(
            taskType: $deterministic['task_type'],
            target: $deterministic['target'],
            materialScope: $this->resolveMaterialScope($deterministic, $facts),
            coverageMode: $deterministic['coverage_mode'],
            crossDocument: $this->resolveCrossDocument($deterministic, $facts),
            requiresReasoning: $deterministic['requires_reasoning'],
            requiresNormatives: $deterministic['requires_normatives'],
            requiresCalculation: $deterministic['requires_calculation'],
            requiresVisualReading: $deterministic['requires_visual_reading'] || $currentImages || $selectedImages || $activeImages,
            domain: $this->domain((string) $facts['domain']),
            confidence: $deterministic['confidence'],
            resolverSource: $source,
            signals: [
                'hints' => array_values(array_unique($signals)),
                'structural' => [
                    'current_material_count' => $currentCount,
                    'selected_material_count' => (int) $facts['selected_material_count'],
                    'active_material_count' => $activeCount,
                    'current_materials_present' => $currentCount > 0,
                    'image_materials_present' => $currentImages || $selectedImages || $activeImages,
                ],
                'explicit_material_ids' => $deterministic['explicit_material_ids'],
            ],
        );

        return $intent;
    }

    /** @param list<string> $currentIds @param list<string> $historicalIds */
    public function resolveForConversation(
        ExpertConversation $conversation,
        string $message,
        array $currentIds,
        array $historicalIds = [],
    ): ExpertTaskIntent {
        $active = $conversation->activeMaterials()->get([
            'expert_project_materials.public_id',
            'expert_project_materials.original_name',
            'expert_project_materials.mime_type',
        ]);
        $ids = array_values(array_unique([...$currentIds, ...$historicalIds, ...$active->pluck('public_id')->all()]));
        $materials = $conversation->project->materials()
            ->whereIn('public_id', $ids)
            ->get(['public_id', 'original_name', 'mime_type'])
            ->keyBy('public_id');
        $toFacts = static function (array $ids) use ($materials): array {
            return array_values(array_map(static function (string $id) use ($materials): array {
                $material = $materials->get($id);

                return [
                    'id' => $id,
                    'name' => $material?->original_name ?? '',
                    'mime_type' => $material?->mime_type ?? '',
                ];
            }, $ids));
        };

        return $this->resolve($message, [
            'current_materials' => $toFacts(array_values(array_unique($currentIds))),
            'active_materials' => $toFacts($active->pluck('public_id')->all()),
            'historical_material_count' => count(array_unique($historicalIds)),
            'domain' => (string) $conversation->project->domain,
        ]);
    }

    /** @param array<string, mixed> $facts @return array<string, mixed> */
    private function normaliseFacts(array $facts): array
    {
        $current = $this->normaliseMaterials($facts['current_materials'] ?? []);
        $selected = $this->normaliseMaterials($facts['selected_materials'] ?? []);
        $active = $this->normaliseMaterials($facts['active_materials'] ?? []);

        return [
            'current_materials' => $current,
            'selected_materials' => $selected,
            'active_materials' => $active,
            'current_material_count' => max(0, (int) ($facts['current_material_count'] ?? count($current))),
            'selected_material_count' => max(0, (int) ($facts['selected_material_count'] ?? count($selected))),
            'active_material_count' => max(0, (int) ($facts['active_material_count'] ?? count($active))),
            'historical_material_count' => max(0, (int) ($facts['historical_material_count'] ?? 0)),
            'domain' => (string) ($facts['domain'] ?? 'generic'),
            'message' => (string) ($facts['message'] ?? ''),
        ];
    }

    /** @param mixed $materials @return list<array{id: string, name: string, mime_type: string}> */
    private function normaliseMaterials(mixed $materials): array
    {
        if (! is_array($materials)) {
            return [];
        }

        return array_values(array_map(static function (mixed $material): array {
            if (is_string($material)) {
                return ['id' => $material, 'name' => '', 'mime_type' => ''];
            }
            if (! is_array($material)) {
                return ['id' => '', 'name' => '', 'mime_type' => ''];
            }

            return [
                'id' => is_string($material['id'] ?? null) ? $material['id'] : '',
                'name' => is_string($material['name'] ?? null) ? $material['name'] : '',
                'mime_type' => is_string($material['mime_type'] ?? null) ? strtolower($material['mime_type']) : '',
            ];
        }, $materials));
    }

    /** @param array<string, mixed> $facts @return array<string, mixed> */
    private function deterministic(string $message, array $facts): array
    {
        $signals = [];
        $explicitIds = $this->explicitMaterialIds($message, $facts['active_materials']);
        if ($explicitIds !== []) {
            $signals[] = 'explicit_material_reference';
        }

        $findWord = preg_match('/\bнайд\p{L}*\b.*\b(?:слово|термин|фраз|упоминан|место)\b/u', $message) === 1;
        $compare = ! $findWord && (
            preg_match('/\bсравн\p{L}*\b|\bсопостав\p{L}*\b|\bразлич\p{L}*\b|\bотлич\p{L}*\b|\bразниц\p{L}*\b/u', $message) === 1
            || preg_match('/\bв\s+чем\b.*\bотлич\p{L}*\b/u', $message) === 1
            || preg_match('/\b(?:какая|какое|кто)\b.*\b(?:лучше|профессиональнее|структурирован\p{L}*)\b/u', $message) === 1
            || preg_match('/\bсопоставительн\p{L}*\s+анализ\b/u', $message) === 1
            || preg_match('/\b(?:проанализируй|проанализировать|анализ)\b.*\b(?:два|две|двух|обе|оба|документ\p{L}*|экспертиз\p{L}*)\b/u', $message) === 1
        );
        if ($compare) {
            $signals[] = 'comparison_hint';
        }

        $exhaustive = preg_match('/\b(?:все|всех|всем|кажд(?:ый|ом|ого)|полный\s+перечень|дословно|перечисл\p{L}*|в\s+каждом|найд\p{L}*\s+все|все\s+упоминан|все\s+противоречия|все\s+реквизиты)\b/u', $message) === 1
            || preg_match('/\b(?:какие|кто|сколько)\b.*\bв\s+этих\s+документ\p{L}*\b/u', $message) === 1;
        if ($exhaustive) {
            $signals[] = 'exhaustive_hint';
        }

        $normatives = preg_match('/\b(?:гост\p{L}*|сп|снип|норматив\p{L}*|стандарт\p{L}*)\b/iu', $message) === 1;
        if ($normatives) {
            $signals[] = 'normative_hint';
        }

        $taskType = ExpertTaskIntent::GENERIC_ANALYSIS;
        $target = null;
        $materialScope = null;
        $crossDocument = null;
        $reasoning = false;
        $calculation = false;
        $visual = false;
        $confidence = 0.35;

        if ($findWord || preg_match('/\b(?:найд\p{L}*|где\s+упомина\p{L}*|отыщ\p{L}*|поиск\p{L}*)\b/u', $message) === 1) {
            $taskType = ExpertTaskIntent::FIND;
            $target = $normatives ? 'normative_references' : null;
            $confidence = 0.9;
            $signals[] = 'find_hint';
        } elseif ($compare) {
            $taskType = ExpertTaskIntent::COMPARE;
            $target = preg_match('/\b(?:стил\p{L}*|профессиональн\p{L}*|структурирован\p{L}*)\b/u', $message) === 1
                ? 'writing_style'
                : null;
            $reasoning = true;
            $confidence = 0.92;
        } elseif (preg_match('/\b(?:противореч\p{L}*|несоответств\p{L}*)\b/u', $message) === 1) {
            $taskType = ExpertTaskIntent::FIND_CONFLICTS;
            $target = 'conflicts';
            $reasoning = true;
            $confidence = 0.9;
        } elseif (preg_match('/\b(?:какие|перечисл\p{L}*|извлек\p{L}*|выдел\p{L}*|выпиш\p{L}*|укаж\p{L}*)\b.*\bвопрос\p{L}*\b/u', $message) === 1) {
            $taskType = ExpertTaskIntent::EXTRACT;
            $target = 'expert_questions';
            $confidence = 0.9;
        } elseif (preg_match('/\b(?:извлек\p{L}*|выпиш\p{L}*|укаж\p{L}*)\b.*\bреквизит\p{L}*\b/u', $message) === 1) {
            $taskType = ExpertTaskIntent::EXTRACT;
            $target = 'requisites';
            $confidence = 0.9;
        } elseif (preg_match('/\b(?:резюм\p{L}*|суммир\p{L}*|кратк\p{L}*\s+(?:содержан\p{L}*|излож\p{L}*))\b/u', $message) === 1) {
            $taskType = ExpertTaskIntent::SUMMARIZE;
            $confidence = 0.88;
        } elseif (preg_match('/\b(?:критическ\p{L}*|реценз\p{L}*|слаб\p{L}*\s+мест\p{L}*|аргументац\p{L}*)\b/u', $message) === 1) {
            $taskType = ExpertTaskIntent::CRITIQUE;
            $reasoning = true;
            $confidence = 0.88;
        } elseif (preg_match('/\b(?:соответств\p{L}*|соблюд\p{L}*|требован\p{L}*)\b/u', $message) === 1 && $normatives) {
            $taskType = ExpertTaskIntent::ASSESS_COMPLIANCE;
            $reasoning = true;
            $confidence = 0.88;
        } elseif (preg_match('/\b(?:почему|причин\p{L}*|из-за\s+чего)\b/u', $message) === 1) {
            $taskType = ExpertTaskIntent::DETERMINE_CAUSE;
            $target = 'cause';
            $reasoning = true;
            $confidence = 0.84;
        } elseif (preg_match('/\b(?:рассчита\p{L}*|посчита\p{L}*|вычисл\p{L}*|калькул\p{L}*)\b/u', $message) === 1) {
            $taskType = ExpertTaskIntent::CALCULATE;
            $target = 'calculation';
            $reasoning = true;
            $calculation = true;
            $confidence = 0.9;
        } elseif (preg_match('/\b(?:составь|подготовь|напиш\p{L}*|сформулиру\p{L}*)\b/u', $message) === 1) {
            $taskType = ExpertTaskIntent::DRAFT;
            $reasoning = true;
            $confidence = 0.8;
        } elseif (preg_match('/\?\s*$|\b(?:что|как|кто|где|когда|каков)\b/u', $message) === 1) {
            $taskType = ExpertTaskIntent::QUESTION_ANSWERING;
            $confidence = 0.62;
        }

        if ($normatives && $target === null) {
            $target = 'normative_references';
        }
        if (! $reasoning && preg_match('/\b(?:проанализир\p{L}*|оцени\p{L}*|проведи\s+анализ|проверь\p{L}*)\b/u', $message) === 1) {
            $reasoning = true;
            $signals[] = 'reasoning_hint';
            $confidence = max($confidence, 0.76);
        }

        return [
            'task_type' => $taskType,
            'target' => $target,
            'material_scope' => $materialScope,
            'cross_document' => $crossDocument,
            'coverage_mode' => $exhaustive ? ExpertTaskIntent::EXHAUSTIVE : ExpertTaskIntent::FOCUSED,
            'requires_reasoning' => $reasoning,
            'requires_normatives' => $normatives,
            'requires_calculation' => $calculation,
            'requires_visual_reading' => false,
            'confidence' => $confidence,
            'signals' => $signals,
            'explicit_material_ids' => $explicitIds,
        ];
    }

    /** @param array<string, mixed> $deterministic @param array<string, mixed> $semantic @param array<string, mixed> $facts @return array<string, mixed> */
    private function mergeSemantic(array $deterministic, array $semantic, array $facts): array
    {
        $semanticTask = (string) ($semantic['task_type'] ?? '');
        if ($deterministic['task_type'] === ExpertTaskIntent::GENERIC_ANALYSIS && in_array($semanticTask, ExpertTaskIntent::TASK_TYPES, true)) {
            $deterministic['task_type'] = $semanticTask;
        }
        foreach ([
            'target' => 'target',
            'material_scope' => 'material_scope',
            'cross_document' => 'cross_document',
            'coverage_mode' => 'coverage_mode',
            'requires_reasoning' => 'requires_reasoning',
            'requires_normatives' => 'requires_normatives',
            'requires_calculation' => 'requires_calculation',
            'requires_visual_reading' => 'requires_visual_reading',
        ] as $from => $to) {
            if (array_key_exists($from, $semantic) && $semantic[$from] !== null) {
                $deterministic[$to] = $semantic[$from];
            }
        }
        if (is_numeric($semantic['confidence'] ?? null)) {
            $deterministic['confidence'] = max((float) $deterministic['confidence'], (float) $semantic['confidence']);
        }
        if (is_array($semantic['signals'] ?? null)) {
            $deterministic['signals'] = [...$deterministic['signals'], ...array_values(array_filter($semantic['signals'], 'is_string'))];
        }

        return $deterministic;
    }

    /** @param array<string, mixed> $semantic */
    private function semanticConfidence(array $semantic): float
    {
        return is_numeric($semantic['confidence'] ?? null) ? max(0.0, min(1.0, (float) $semantic['confidence'])) : 0.0;
    }

    /** @param array<string, mixed> $deterministic @param array<string, mixed> $facts */
    private function resolveMaterialScope(array $deterministic, array $facts): string
    {
        if ((int) $facts['current_material_count'] > 0) {
            return ExpertTaskIntent::CURRENT;
        }
        if ((int) $facts['selected_material_count'] > 0) {
            return ExpertTaskIntent::SELECTED;
        }
        if ($deterministic['explicit_material_ids'] !== []) {
            return ExpertTaskIntent::EXPLICIT;
        }
        if ((int) $facts['historical_material_count'] > 0) {
            return ExpertTaskIntent::EXPLICIT;
        }
        if (is_string($deterministic['material_scope'] ?? null)
            && in_array($deterministic['material_scope'], ExpertTaskIntent::MATERIAL_SCOPES, true)) {
            return $deterministic['material_scope'];
        }
        if ((int) $facts['active_material_count'] > 1 && $this->isAmbiguous($deterministic)) {
            return ExpertTaskIntent::AMBIGUOUS;
        }
        if ((int) $facts['active_material_count'] > 0) {
            return ExpertTaskIntent::ACTIVE;
        }

        return ExpertTaskIntent::PROJECT;
    }

    /** @param array<string, mixed> $deterministic @param array<string, mixed> $facts */
    private function resolveCrossDocument(array $deterministic, array $facts): bool
    {
        if (($deterministic['cross_document'] ?? false) === true) {
            return true;
        }
        if (in_array($deterministic['task_type'], [ExpertTaskIntent::COMPARE, ExpertTaskIntent::FIND_CONFLICTS], true)) {
            return (int) $facts['current_material_count'] > 1
                || (int) $facts['selected_material_count'] > 1
                || (int) $facts['active_material_count'] > 1
                || count($deterministic['explicit_material_ids']) > 1
                || preg_match('/\b(?:два|две|двух|обе|оба|документ\p{L}*|экспертиз\p{L}*)\b/u', $this->normalize((string) ($facts['message'] ?? ''))) === 1;
        }

        return false;
    }

    /** @param array<string, mixed> $deterministic */
    private function isAmbiguous(array $deterministic): bool
    {
        return $deterministic['task_type'] === ExpertTaskIntent::QUESTION_ANSWERING
            && $deterministic['target'] === null;
    }

    /** @param list<array{id: string, name: string, mime_type: string}> $materials */
    private function containsImage(array $materials): bool
    {
        foreach ($materials as $material) {
            if (str_starts_with(strtolower($material['mime_type']), 'image/')) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{id: string, name: string, mime_type: string}> $materials @return list<string> */
    private function explicitMaterialIds(string $message, array $materials): array
    {
        $ids = [];
        foreach ($materials as $material) {
            $name = $this->normalize($material['name']);
            $stem = preg_replace('/\.[^.]+$/u', '', $name) ?? '';
            if (($name !== '' && str_contains($message, $name)) || ($stem !== '' && mb_strlen($stem) >= 3 && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($stem, '/').'(?![\p{L}\p{N}])/u', $message) === 1)) {
                if ($material['id'] !== '') {
                    $ids[] = $material['id'];
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private function domain(string $value): string
    {
        return match (strtolower($value)) {
            'commodity', 'merchandise', 'merchandise_expertise' => 'merchandise',
            'construction', 'construction_expertise' => 'construction',
            default => 'generic',
        };
    }

    private function normalize(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower(str_replace('ё', 'е', $value), 'UTF-8')) ?? '');
    }
}
