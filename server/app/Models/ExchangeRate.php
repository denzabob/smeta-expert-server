<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    use HasFactory;

    protected $fillable = [
        'from_currency',
        'to_currency',
        'rate',
        'rate_date',
        'source',
    ];

    protected $casts = [
        'rate' => 'decimal:6',
        'rate_date' => 'date',
    ];

    public const SOURCE_CBR = 'cbr';
    public const SOURCE_ECB = 'ecb';
    public const SOURCE_MANUAL = 'manual';

    /**
     * Get rate for specific date.
     */
    public static function getRate(string $from, string $to, ?\DateTimeInterface $date = null): ?float
    {
        $date = $date ?? now();

        // Direct rate
        $rate = self::where('from_currency', $from)
            ->where('to_currency', $to)
            ->where('rate_date', '<=', $date)
            ->orderBy('rate_date', 'desc')
            ->first();

        if ($rate) {
            return (float) $rate->rate;
        }

        // Reverse rate
        $reverseRate = self::where('from_currency', $to)
            ->where('to_currency', $from)
            ->where('rate_date', '<=', $date)
            ->orderBy('rate_date', 'desc')
            ->first();

        if ($reverseRate && $reverseRate->rate != 0) {
            return 1 / (float) $reverseRate->rate;
        }

        return null;
    }

    /**
     * Convert amount between currencies.
     */
    public static function convert(float $amount, string $from, string $to, ?\DateTimeInterface $date = null): ?float
    {
        if ($from === $to) {
            return $amount;
        }

        $rate = self::getRate($from, $to, $date);
        
        if ($rate === null) {
            return null;
        }

        return $amount * $rate;
    }
}
