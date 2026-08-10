<?php

namespace App\Domain\PriceIndices\Infrastructure\Import;

use App\Domain\PriceIndices\Application\Data\NormalizedStatisticalValue;
use App\Domain\PriceIndices\Domain\Enums\StatisticalObservationMissingReason;
use App\Domain\PriceIndices\Domain\Exceptions\StatisticalImportParsingFailed;
use PhpOffice\PhpSpreadsheet\RichText\RichText;

class StatisticalNumericValueNormalizer
{
    public function normalize(mixed $rawValue, string $cellType, string $numberFormat = '0.00'): NormalizedStatisticalValue
    {
        $plain = $rawValue instanceof RichText ? $rawValue->getPlainText() : (string) ($rawValue ?? '');
        $trimmed = trim(str_replace("\u{00A0}", ' ', $plain));

        $missing = match ($trimmed) {
            '' => StatisticalObservationMissingReason::Blank,
            '…' => StatisticalObservationMissingReason::Ellipsis,
            '...' => StatisticalObservationMissingReason::ThreeDots,
            '-' => StatisticalObservationMissingReason::Dash,
            default => null,
        };
        if ($missing !== null) {
            return new NormalizedStatisticalValue(null, $missing, $plain);
        }

        if ($cellType === 'f' || str_starts_with($trimmed, '=')) {
            throw new StatisticalImportParsingFailed('formula_in_supported_cell', 'Formula found in a supported value cell.');
        }

        if (preg_match('/^(\d+),(\d{2})(\d\))$/u', $trimmed, $matches) === 1) {
            return new NormalizedStatisticalValue(
                $matches[1].'.'.$matches[2],
                null,
                $plain,
                $matches[3],
                true,
            );
        }

        if (! is_int($rawValue) && ! is_float($rawValue)
            && preg_match('/^[+-]?\d+(?:[.,]\d+)?$/', $trimmed) !== 1
        ) {
            throw new StatisticalImportParsingFailed('invalid_numeric_value', 'Unsupported statistical numeric value.');
        }

        $lexical = str_replace(',', '.', $trimmed);
        $decimals = $this->decimalPlaces($numberFormat);

        return new NormalizedStatisticalValue(
            $this->roundDecimal($lexical, $decimals),
            null,
            $plain,
        );
    }

    private function decimalPlaces(string $format): int
    {
        if (preg_match('/0\.([0#]+)/', $format, $matches) === 1) {
            return strlen($matches[1]);
        }

        return 2;
    }

    private function roundDecimal(string $value, int $scale): string
    {
        $negative = str_starts_with($value, '-');
        $unsigned = ltrim($value, '+-');
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $fraction = preg_replace('/\D/', '', $fraction) ?? '';
        $roundDigit = (int) ($fraction[$scale] ?? '0');
        $kept = str_pad(substr($fraction, 0, $scale), $scale, '0');
        $digits = ltrim($whole.$kept, '0');
        $digits = $digits === '' ? '0' : $digits;

        if ($roundDigit >= 5) {
            $digits = $this->incrementDigits($digits);
        }

        $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
        $wholeResult = $scale === 0 ? $digits : substr($digits, 0, -$scale);
        $fractionResult = $scale === 0 ? '' : '.'.substr($digits, -$scale);
        $prefix = $negative && trim($digits, '0') !== '' ? '-' : '';

        return $prefix.$wholeResult.$fractionResult;
    }

    private function incrementDigits(string $digits): string
    {
        $chars = str_split($digits);
        for ($index = count($chars) - 1; $index >= 0; $index--) {
            if ($chars[$index] !== '9') {
                $chars[$index] = (string) ((int) $chars[$index] + 1);

                return implode('', $chars);
            }
            $chars[$index] = '0';
        }

        return '1'.implode('', $chars);
    }
}
