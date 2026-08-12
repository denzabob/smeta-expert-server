<?php

namespace App\Domain\PriceIndices\Application\Support;

use DateTimeInterface;

final class PublicIndexFormatter
{
    private const MONTHS = [
        1 => 'январь', 2 => 'февраль', 3 => 'март', 4 => 'апрель',
        5 => 'май', 6 => 'июнь', 7 => 'июль', 8 => 'август',
        9 => 'сентябрь', 10 => 'октябрь', 11 => 'ноябрь', 12 => 'декабрь',
    ];

    private const GENITIVE_MONTHS = [
        1 => 'января', 2 => 'февраля', 3 => 'марта', 4 => 'апреля',
        5 => 'мая', 6 => 'июня', 7 => 'июля', 8 => 'августа',
        9 => 'сентября', 10 => 'октября', 11 => 'ноября', 12 => 'декабря',
    ];

    public function period(DateTimeInterface $date, bool $capitalize = false): string
    {
        $value = self::MONTHS[(int) $date->format('n')].' '.$date->format('Y');

        return $capitalize ? mb_strtoupper(mb_substr($value, 0, 1)).mb_substr($value, 1) : $value;
    }

    public function periodGenitive(DateTimeInterface $date): string
    {
        return self::GENITIVE_MONTHS[(int) $date->format('n')].' '.$date->format('Y');
    }

    public function periodRange(DateTimeInterface $from, DateTimeInterface $to): string
    {
        return $this->period($from, true).' — '.$this->period($to);
    }

    public function coefficient(?string $value): string
    {
        return $this->decimal($value, 12);
    }

    public function indexValue(?string $value): string
    {
        return $this->decimal($value, 2, true);
    }

    public function percent(?string $value): string
    {
        if ($value === null) {
            return '—';
        }

        $negative = str_starts_with($value, '-');
        $unsigned = ltrim($value, '+-');
        $isZero = preg_match('/^0+(?:\.0+)?$/D', $unsigned) === 1;
        $prefix = $isZero ? '' : ($negative ? '−' : '+');

        return $prefix.$this->decimal($unsigned, 2).' %';
    }

    public function detailTitle(string $itemName, int $latestDataYear): string
    {
        $itemName = $this->lowerFirst($itemName);
        $candidates = [
            "Индекс цен производителей на {$itemName} {$latestDataYear} — Росстат | ПРИЗМА",
            "Индекс цен на {$itemName} {$latestDataYear} — Росстат | ПРИЗМА",
            "Индекс цен на {$itemName} {$latestDataYear} — Росстат",
            "Индекс цен на {$itemName} {$latestDataYear}",
        ];

        foreach ($candidates as $candidate) {
            if (mb_strlen($candidate, 'UTF-8') <= 65) {
                return $candidate;
            }
        }

        $prefix = 'Индекс цен на ';
        $suffix = ' '.$latestDataYear;
        $available = 65 - mb_strlen($prefix.$suffix, 'UTF-8');

        return $prefix.$this->truncate($itemName, $available).$suffix;
    }

    public function heading(string $itemName): string
    {
        return 'Индекс цен производителей на '.$this->lowerFirst($itemName);
    }

    public function description(
        string $itemName,
        DateTimeInterface $from,
        DateTimeInterface $to,
        string $changePercent,
        string $coefficient,
        string $provider,
    ): string {
        return $this->truncate(sprintf(
            'Индекс цен производителей на %s: изменение %s с %s по %s, коэффициент %s. Данные %s.',
            $this->lowerFirst($itemName),
            $changePercent,
            $this->periodGenitive($from),
            $this->period($to),
            $coefficient,
            $provider,
        ), 190);
    }

    public function shortPublicId(string $publicId): string
    {
        return mb_substr($publicId, 0, 8);
    }

    private function decimal(?string $value, int $minimumScale, bool $trim = false): string
    {
        if ($value === null || preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/D', $value, $matches) !== 1) {
            return '—';
        }

        $fraction = $matches[3] ?? '';
        if ($trim) {
            $fraction = rtrim($fraction, '0');
        }
        $fraction = str_pad($fraction, $minimumScale, '0');

        return $matches[1].$matches[2].($fraction !== '' ? ','.$fraction : '');
    }

    private function lowerFirst(string $value): string
    {
        return mb_strtolower(mb_substr($value, 0, 1), 'UTF-8').mb_substr($value, 1);
    }

    private function truncate(string $value, int $limit): string
    {
        return mb_strlen($value, 'UTF-8') <= $limit
            ? $value
            : rtrim(mb_substr($value, 0, $limit - 1, 'UTF-8')).'…';
    }
}
