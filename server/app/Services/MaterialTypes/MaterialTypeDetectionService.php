<?php

namespace App\Services\MaterialTypes;

use App\Models\Material;
use App\Models\MaterialTypePattern;

class MaterialTypeDetectionService
{
    public const DEFAULT_TYPE = Material::TYPE_HARDWARE;

    public const SUPPORTED_TYPES = [
        Material::TYPE_PLATE,
        Material::TYPE_EDGE,
        Material::TYPE_HARDWARE,
        Material::TYPE_FACADE,
        'fitting',
    ];

    public function resolve(
        string $title,
        ?string $url = null,
        ?string $source = null,
        ?string $existingType = null
    ): array {
        $detected = $this->detect($title, $url, $source);

        $resolvedType = $detected['type'];
        $wasTypeLocked = false;
        $lockReason = null;

        if ($this->isSupportedType($existingType) && $existingType !== $detected['type']) {
            $resolvedType = $existingType;
            $wasTypeLocked = true;
            $lockReason = 'existing_material_type_preserved';
        }

        return [
            'detected_type' => $detected['type'],
            'resolved_type' => $resolvedType,
            'detected_unit' => self::unitForType($detected['type']),
            'resolved_unit' => self::unitForType($resolvedType),
            'decision' => $detected['decision'],
            'reason' => $detected['reason'],
            'matched_pattern' => $detected['matched_pattern'],
            'was_type_locked' => $wasTypeLocked,
            'lock_reason' => $lockReason,
            'normalized_title' => $detected['normalized_title'],
            'source' => $source,
        ];
    }

    public function detect(string $title, ?string $url = null, ?string $source = null): array
    {
        $normalizedTitle = $this->normalizeTitle($title);
        $normalizedSource = $this->normalizeSource($source);
        $normalizedUrl = mb_strtolower(trim((string) ($url ?? '')));

        $patterns = MaterialTypePattern::query()
            ->where('is_active', true)
            ->where(function ($query) use ($normalizedSource) {
                $query->whereNull('source');
                if ($normalizedSource !== null) {
                    $query->orWhere('source', $normalizedSource);
                }
            })
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        foreach ($patterns as $pattern) {
            $haystack = $this->buildHaystack($pattern, $title, $normalizedTitle, $url, $normalizedUrl);
            if ($haystack === '') {
                continue;
            }

            $flags = trim((string) ($pattern->flags ?: 'iu'));
            $expression = '~' . $pattern->pattern . '~' . $flags;
            $matches = [];

            if (@preg_match($expression, $haystack, $matches) !== 1) {
                continue;
            }

            return [
                'type' => $pattern->material_type,
                'decision' => 'matched_pattern',
                'reason' => 'matched_db_pattern',
                'matched_pattern' => [
                    'id' => $pattern->id,
                    'name' => $pattern->name,
                    'priority' => (int) $pattern->priority,
                    'material_type' => $pattern->material_type,
                    'source' => $pattern->source,
                    'target_field' => $pattern->target_field,
                    'pattern' => $pattern->pattern,
                    'flags' => $flags,
                    'matched_value' => $matches[0] ?? null,
                ],
                'normalized_title' => $normalizedTitle,
            ];
        }

        return [
            'type' => self::DEFAULT_TYPE,
            'decision' => 'fallback_default',
            'reason' => 'no_pattern_match',
            'matched_pattern' => null,
            'normalized_title' => $normalizedTitle,
        ];
    }

    public function preview(array $candidatePattern, string $title, ?string $url = null): array
    {
        $targetField = $candidatePattern['target_field'] ?? MaterialTypePattern::TARGET_TITLE;
        $useNormalized = (bool) ($candidatePattern['use_normalized_text'] ?? true);
        $flags = trim((string) ($candidatePattern['flags'] ?? 'iu'));
        $pattern = trim((string) ($candidatePattern['pattern'] ?? ''));

        $titleRaw = trim($title);
        $titleNormalized = $this->normalizeTitle($titleRaw);
        $urlRaw = trim((string) ($url ?? ''));
        $urlNormalized = mb_strtolower($urlRaw);

        $titlePayload = $useNormalized ? $titleNormalized : $titleRaw;
        $urlPayload = $useNormalized ? $urlNormalized : $urlRaw;

        $haystack = match ($targetField) {
            MaterialTypePattern::TARGET_URL => $urlPayload,
            MaterialTypePattern::TARGET_TITLE_OR_URL => trim($titlePayload . ' ' . $urlPayload),
            default => $titlePayload,
        };

        $expression = '~' . $pattern . '~' . $flags;
        $matches = [];
        $isMatched = $pattern !== '' && $haystack !== '' && @preg_match($expression, $haystack, $matches) === 1;

        return [
            'matched' => $isMatched,
            'material_type' => $candidatePattern['material_type'] ?? self::DEFAULT_TYPE,
            'unit' => self::unitForType($candidatePattern['material_type'] ?? self::DEFAULT_TYPE),
            'target_field' => $targetField,
            'expression' => $expression,
            'matched_value' => $matches[0] ?? null,
            'haystack' => $haystack,
            'normalized_title' => $titleNormalized,
        ];
    }

    public static function unitForType(string $materialType): string
    {
        return match ($materialType) {
            Material::TYPE_EDGE => 'м.п.',
            Material::TYPE_PLATE => 'м²',
            default => 'шт',
        };
    }

    private function buildHaystack(
        MaterialTypePattern $pattern,
        string $titleRaw,
        string $titleNormalized,
        ?string $urlRaw,
        string $urlNormalized
    ): string {
        $titlePayload = $pattern->use_normalized_text ? $titleNormalized : trim($titleRaw);
        $urlPayload = $pattern->use_normalized_text ? $urlNormalized : trim((string) ($urlRaw ?? ''));

        return match ($pattern->target_field) {
            MaterialTypePattern::TARGET_URL => $urlPayload,
            MaterialTypePattern::TARGET_TITLE_OR_URL => trim($titlePayload . ' ' . $urlPayload),
            default => $titlePayload,
        };
    }

    private function normalizeTitle(string $title): string
    {
        $lower = mb_strtolower(trim($title));
        $normalized = preg_replace('/\s+/u', ' ', $lower);
        return trim((string) $normalized);
    }

    private function normalizeSource(?string $source): ?string
    {
        $normalized = trim((string) ($source ?? ''));
        return $normalized === '' ? null : $normalized;
    }

    private function isSupportedType(?string $materialType): bool
    {
        if ($materialType === null || $materialType === '') {
            return false;
        }

        return in_array($materialType, self::SUPPORTED_TYPES, true);
    }
}
