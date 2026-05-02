<?php

namespace App\Services;

class EdgeProcessingService
{
    public static function calculateLength(string $type, float $width, float $length): float
    {
        if ($width <= 0 || $length <= 0) {
            return 0;
        }

        return match ($type) {
            'none' => 0,
            'O' => ($width + $length) * 2,
            '=' => $length * 2,
            '||' => $width * 2,
            'L' => $width + $length,
            'П' => ($length * 2) + $width,
            'long_one' => $length,
            'short_one' => $width,
            default => throw new \InvalidArgumentException("Неизвестная схема кромки: {$type}"),
        };
    }
}
