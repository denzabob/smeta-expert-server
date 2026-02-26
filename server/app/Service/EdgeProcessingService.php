<?php
// app/Services/EdgeProcessingService.php
namespace App\Services;

class EdgeProcessingService
{
    public static function calculateLength(string $type, float $width, float $length): float
    {
        if ($width <= 0 || $length <= 0) {
            return 0; // или throw
        }

        return match ($type) {
            'none' => 0,
            'O' => ($width + $length) * 2,
            '=' => $length * 2,
            '||' => $width * 2,
            'L' => $width + $length,
            'П' => ($length * 2) + $width,
            default => throw new \InvalidArgumentException("Неизвестная схема кромки: {$type}"),
        };
    }
}
