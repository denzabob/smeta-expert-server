<?php

namespace App\Utilities;

use InvalidArgumentException;

/**
 * Utility class for normalizing numeric values from various formats.
 * 
 * Handles:
 * - Numbers with spaces as thousand separators: "1 234" -> 1234
 * - Comma as decimal separator: "123,45" -> 123.45
 * - Mixed formats: "1 234,56" -> 1234.56
 * - Standard formats: "123.45" -> 123.45
 */
class NumericNormalizer
{
    /**
     * Normalize a string to a float value.
     *
     * @param string|int|float|null $value The value to normalize
     * @return float|null Returns null if the value cannot be parsed
     */
    public static function toFloat(string|int|float|null $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        // Trim whitespace
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        // Remove all spaces (thousand separators)
        $value = preg_replace('/\s+/', '', $value);

        // Determine decimal separator
        // If there's a comma after a dot, comma is decimal separator: "1.234,56"
        // If there's a dot after a comma, dot is decimal separator: "1,234.56"
        // If only comma exists, it's likely decimal separator (European format)
        // If only dot exists, it's likely decimal separator (US format)
        
        $hasComma = str_contains($value, ',');
        $hasDot = str_contains($value, '.');
        
        if ($hasComma && $hasDot) {
            // Both exist - the last one is the decimal separator
            $lastComma = strrpos($value, ',');
            $lastDot = strrpos($value, '.');
            
            if ($lastComma > $lastDot) {
                // Comma is decimal separator: "1.234,56" -> "1234.56"
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                // Dot is decimal separator: "1,234.56" -> "1234.56"
                $value = str_replace(',', '', $value);
            }
        } elseif ($hasComma) {
            // Only comma - treat as decimal separator
            // Check if it looks like thousand separator (e.g., "1,234")
            if (preg_match('/^\d{1,3}(,\d{3})+$/', $value)) {
                // Looks like US thousand separator without decimals
                $value = str_replace(',', '', $value);
            } else {
                // Treat comma as decimal separator
                $value = str_replace(',', '.', $value);
            }
        }
        // If only dot or neither - standard format, no changes needed

        // Validate the result is a valid number
        if (!is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    /**
     * Normalize a string to an integer value.
     *
     * @param string|int|float|null $value The value to normalize
     * @param bool $strict If true, non-integer values return null; if false, rounds to nearest int
     * @return int|null Returns null if the value cannot be parsed
     * @throws InvalidArgumentException If strict mode and value has decimals
     */
    public static function toInt(string|int|float|null $value, bool $strict = true): ?int
    {
        $floatValue = self::toFloat($value);

        if ($floatValue === null) {
            return null;
        }

        // Check if value has decimal part
        $intValue = (int) $floatValue;
        $hasDecimals = abs($floatValue - $intValue) > 0.0001;

        if ($strict && $hasDecimals) {
            return null; // In strict mode, decimals are not allowed for integers
        }

        return $intValue;
    }

    /**
     * Normalize a value and ensure it's positive.
     *
     * @param string|int|float|null $value The value to normalize
     * @return float|null Returns null if value is not positive
     */
    public static function toPositiveFloat(string|int|float|null $value): ?float
    {
        $result = self::toFloat($value);
        
        if ($result === null || $result <= 0) {
            return null;
        }

        return $result;
    }

    /**
     * Normalize a value and ensure it's a positive integer.
     *
     * @param string|int|float|null $value The value to normalize
     * @param bool $strict If true, non-integer values return null
     * @return int|null Returns null if value is not a positive integer
     */
    public static function toPositiveInt(string|int|float|null $value, bool $strict = true): ?int
    {
        $result = self::toInt($value, $strict);
        
        if ($result === null || $result <= 0) {
            return null;
        }

        return $result;
    }

    /**
     * Convert a length value from one unit to millimeters.
     *
     * @param float $value The value to convert
     * @param string $fromUnit The source unit (mm, cm, m)
     * @return float The value in millimeters
     * @throws InvalidArgumentException If the unit is not recognized
     */
    public static function convertToMm(float $value, string $fromUnit): float
    {
        return match (strtolower($fromUnit)) {
            'mm' => $value,
            'cm' => $value * 10,
            'm' => $value * 1000,
            default => throw new InvalidArgumentException("Unknown unit: {$fromUnit}. Supported: mm, cm, m"),
        };
    }

    /**
     * Check if a value looks like it might be in a different unit.
     * This is a heuristic check, not definitive.
     *
     * @param float $value The value in supposedly mm
     * @return string|null Suggested unit if suspicious, null if looks like mm
     */
    public static function suggestUnit(float $value): ?string
    {
        // Very small values might be in meters (e.g., 0.5 instead of 500mm)
        if ($value < 10 && $value > 0) {
            return 'm';
        }
        
        // Values between 10 and 100 might be in cm
        if ($value >= 10 && $value < 100) {
            return 'cm';
        }

        // Values >= 100 look reasonable for mm
        return null;
    }
}
