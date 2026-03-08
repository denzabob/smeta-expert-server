<?php

namespace App\Services\MaterialDimensions;

class DimensionTextNormalizer
{
    public function normalize(string $text): string
    {
        $normalized = mb_strtolower($text, 'UTF-8');
        $normalized = str_replace(["\r", "\n", "\t"], ' ', $normalized);

        // Unify separators to latin x.
        $normalized = preg_replace('/[xх×*]/u', 'x', $normalized);

        // Unify millimeters marker.
        $normalized = preg_replace('/\b(?:мм|mm|миллиметр(?:а|ов)?)\b/iu', ' mm ', $normalized);

        // Unify decimal separator.
        $normalized = preg_replace('/(\d)\s*,\s*(\d)/u', '$1.$2', $normalized);

        // Remove punctuation noise but keep letters, numbers, dots and x.
        $normalized = preg_replace('/[^\p{L}\p{N}\.\sx]/u', ' ', $normalized);
        $normalized = preg_replace('/\s+/u', ' ', $normalized);

        return trim($normalized);
    }
}
