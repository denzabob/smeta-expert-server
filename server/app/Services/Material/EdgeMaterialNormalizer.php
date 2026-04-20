<?php

namespace App\Services\Material;

use App\Models\Material;
use Illuminate\Support\Facades\Log;

class EdgeMaterialNormalizer
{
    /**
     * Normalize edge material dimensions to canonical fields.
     *
     * Canonical rules for edge:
     * - length_mm = edge width (e.g. 19, 36)
     * - thickness = decimal edge thickness (e.g. 0.4, 1.0, 2.0)
     * - thickness_mm = optional integer mirror for whole values only
     * - width_mm is never used to store edge thickness
     */
    public function normalize(array $data): array
    {
        if (($data['type'] ?? null) !== Material::TYPE_EDGE) {
            return $data;
        }

        $parsed = $this->parseDimensionsFromText(
            trim((string) ($data['name'] ?? '')),
            trim((string) ($data['article'] ?? ''))
        );

        $explicitLength = $this->toPositiveInt($data['length_mm'] ?? null);
        $legacyWidthAsLength = $explicitLength === null
            ? $this->toLengthFromWidthField($data['width_mm'] ?? null)
            : null;

        $explicitThickness = $this->firstPositiveFloat(
            $data['thickness'] ?? null,
            $data['thickness_mm'] ?? null
        );

        $legacyWidthAsThickness = $explicitThickness === null
            ? $this->toThicknessFromWidthField($data['width_mm'] ?? null)
            : null;

        $resolvedLength = $explicitLength
            ?? $parsed['length_mm']
            ?? $legacyWidthAsLength;

        $resolvedThickness = $explicitThickness
            ?? $parsed['thickness']
            ?? $legacyWidthAsThickness;

        $source = $this->resolveSource(
            $explicitLength !== null || $legacyWidthAsLength !== null,
            $explicitThickness !== null || $legacyWidthAsThickness !== null,
            $parsed['length_mm'] !== null || $parsed['thickness'] !== null,
            $resolvedLength !== null,
            $resolvedThickness !== null
        );

        $normalized = $data;
        $normalized['length_mm'] = $resolvedLength;
        $normalized['thickness'] = $resolvedThickness !== null ? round($resolvedThickness, 2) : null;
        $normalized['thickness_mm'] = $this->normalizeThicknessMm($resolvedThickness);
        $normalized['width_mm'] = null;

        Log::debug('edge_material_normalized', [
            'input' => [
                'name' => $data['name'] ?? null,
                'article' => $data['article'] ?? null,
                'length_mm' => $data['length_mm'] ?? null,
                'width_mm' => $data['width_mm'] ?? null,
                'thickness' => $data['thickness'] ?? null,
                'thickness_mm' => $data['thickness_mm'] ?? null,
            ],
            'output' => [
                'length_mm' => $normalized['length_mm'],
                'thickness' => $normalized['thickness'],
                'thickness_mm' => $normalized['thickness_mm'],
                'width_mm' => $normalized['width_mm'],
            ],
            'source' => $source,
        ]);

        return $normalized;
    }

    private function parseDimensionsFromText(string ...$parts): array
    {
        $text = trim(implode(' ', array_filter($parts, static fn (?string $part): bool => $part !== null && $part !== '')));

        if ($text === '') {
            return [
                'length_mm' => null,
                'thickness' => null,
            ];
        }

        if (!preg_match('/\b(\d{1,3})\s*[xх×]\s*(\d{1,2}(?:[.,]\d+)?)\b/u', $text, $matches)) {
            return [
                'length_mm' => null,
                'thickness' => null,
            ];
        }

        $length = $this->toPositiveInt($matches[1] ?? null);
        $thickness = $this->toPositiveFloat($matches[2] ?? null);

        return [
            'length_mm' => $length,
            'thickness' => $thickness,
        ];
    }

    private function firstPositiveFloat(mixed ...$values): ?float
    {
        foreach ($values as $value) {
            $normalized = $this->toPositiveFloat($value);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    private function toPositiveFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = str_replace(',', '.', trim((string) $value));
        if (!is_numeric($normalized)) {
            return null;
        }

        $float = (float) $normalized;

        return $float > 0 ? $float : null;
    }

    private function toPositiveInt(mixed $value): ?int
    {
        $float = $this->toPositiveFloat($value);
        if ($float === null) {
            return null;
        }

        return (int) round($float);
    }

    private function toLengthFromWidthField(mixed $value): ?int
    {
        $candidate = $this->toPositiveFloat($value);
        if ($candidate === null || $candidate < 10) {
            return null;
        }

        return (int) round($candidate);
    }

    private function toThicknessFromWidthField(mixed $value): ?float
    {
        $candidate = $this->toPositiveFloat($value);
        if ($candidate === null || $candidate > 10) {
            return null;
        }

        return $candidate;
    }

    private function normalizeThicknessMm(?float $thickness): ?int
    {
        if ($thickness === null) {
            return null;
        }

        $rounded = round($thickness);
        if (abs($thickness - $rounded) > 0.0001) {
            return null;
        }

        return $rounded > 0 ? (int) $rounded : null;
    }

    private function resolveSource(
        bool $usedExplicitLength,
        bool $usedExplicitThickness,
        bool $parsedAvailable,
        bool $resolvedLength,
        bool $resolvedThickness
    ): string {
        $usedExplicit = $usedExplicitLength || $usedExplicitThickness;
        $usedParsed = $parsedAvailable
            && (
                (!$usedExplicitLength && $resolvedLength)
                || (!$usedExplicitThickness && $resolvedThickness)
            );

        if ($usedExplicit && $usedParsed) {
            return 'mixed';
        }

        if ($usedExplicit) {
            return 'explicit';
        }

        if ($usedParsed) {
            return 'parsed_name';
        }

        return 'explicit';
    }
}
