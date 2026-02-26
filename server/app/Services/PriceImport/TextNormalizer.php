<?php

namespace App\Services\PriceImport;

/**
 * Нормализатор текста для поиска кандидатов
 */
class TextNormalizer
{
    /**
     * Normalize text for search matching.
     */
    public static function normalize(string $text): string
    {
        // Convert to lowercase
        $text = mb_strtolower($text, 'UTF-8');
        
        // Remove extra whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        
        // Remove quotes and special chars
        $text = str_replace(['"', "'", '«', '»', '„', '“', '”', '‚', '’', '‹', '›'], '', $text);
        
        // Remove punctuation at word boundaries
        $text = preg_replace('/[,;:!?\.]+/', ' ', $text);
        
        // Normalize dashes
        $text = str_replace(['–', '—', '−'], '-', $text);
        
        // Remove multiple spaces
        $text = preg_replace('/\s+/', ' ', $text);
        
        return trim($text);
    }

    /**
     * Generate search tokens from text.
     */
    public static function tokenize(string $text): array
    {
        $normalized = self::normalize($text);
        
        // Split by whitespace and filter empty
        $tokens = preg_split('/\s+/', $normalized);
        $tokens = array_filter($tokens, fn($t) => strlen($t) >= 2);
        
        return array_values(array_unique($tokens));
    }

    /**
     * Generate n-grams for fuzzy matching.
     */
    public static function trigrams(string $text): array
    {
        $normalized = self::normalize($text);
        $normalized = str_replace(' ', '', $normalized); // Remove spaces for trigrams
        
        $trigrams = [];
        $len = mb_strlen($normalized);
        
        for ($i = 0; $i <= $len - 3; $i++) {
            $trigrams[] = mb_substr($normalized, $i, 3);
        }
        
        return array_unique($trigrams);
    }

    /**
     * Calculate trigram similarity (Jaccard coefficient).
     */
    public static function trigramSimilarity(string $a, string $b): float
    {
        $trigramsA = self::trigrams($a);
        $trigramsB = self::trigrams($b);
        
        if (empty($trigramsA) || empty($trigramsB)) {
            return 0.0;
        }
        
        $intersection = array_intersect($trigramsA, $trigramsB);
        $union = array_unique(array_merge($trigramsA, $trigramsB));
        
        return count($intersection) / count($union);
    }

    /**
     * Calculate Levenshtein similarity (0-1).
     */
    public static function levenshteinSimilarity(string $a, string $b): float
    {
        $a = self::normalize($a);
        $b = self::normalize($b);
        
        $maxLen = max(mb_strlen($a), mb_strlen($b));
        
        if ($maxLen === 0) {
            return 1.0;
        }
        
        // Use similar_text for multibyte strings (faster than levenshtein for long strings)
        $similarity = 0;
        similar_text($a, $b, $similarity);
        
        return $similarity / 100;
    }

    /**
     * Combined similarity score.
     */
    public static function combinedSimilarity(string $a, string $b): float
    {
        $trigram = self::trigramSimilarity($a, $b);
        $levenshtein = self::levenshteinSimilarity($a, $b);
        
        // Weighted average: trigram is faster and good for fuzzy, levenshtein for precision
        return ($trigram * 0.6) + ($levenshtein * 0.4);
    }

    /**
     * Extract potential SKU/article from text.
     */
    public static function extractSku(string $text): ?string
    {
        // Look for patterns like: ABC-123, ABC123, 123-ABC-456
        if (preg_match('/\b([A-Z0-9]{2,}[-_]?[A-Z0-9]+)\b/i', $text, $matches)) {
            return strtoupper($matches[1]);
        }
        
        return null;
    }

    /**
     * Extract numeric value from price string.
     */
    public static function extractPrice(string $text): ?float
    {
        // Remove currency symbols and spaces
        $text = preg_replace('/[₽$€£¥\s\xa0]+/', '', $text);
        
        // Handle different decimal separators
        // Russian: 1 234,56 or 1234.56
        $text = preg_replace('/\s/', '', $text);
        
        // Replace comma with dot if it's decimal separator
        if (preg_match('/^\d+,\d{1,2}$/', $text)) {
            $text = str_replace(',', '.', $text);
        } else {
            // Remove thousands separator (comma or space)
            $text = str_replace(',', '', $text);
        }
        
        if (is_numeric($text)) {
            return (float) $text;
        }
        
        return null;
    }
}
