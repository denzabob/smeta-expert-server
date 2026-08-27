<?php

namespace App\Domain\PriceIndices\Application\Support;

use App\Domain\PriceIndices\Application\Data\PublicIndexFamilyDescriptor;
use App\Domain\PriceIndices\Application\Services\PublicIndexFamilyRegistry;
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

    public function detailTitle(
        string $itemCode,
        string $itemName,
        string $indicatorType,
        ?string $classifierLabel,
    ): string {
        $code = trim(($classifierLabel === null ? '' : $classifierLabel.' ').$itemCode);
        $indicator = $this->lowerFirst(trim($indicatorType));
        $prefix = $code.' — ';
        $suffix = ': '.$indicator.' | ПРИЗМА';
        $available = max(12, 95 - mb_strlen($prefix.$suffix, 'UTF-8'));

        return $prefix.$this->truncate(trim($itemName), $available).$suffix;
    }

    public function heading(string $itemCode, string $itemName, ?string $classifierLabel): string
    {
        $code = trim(($classifierLabel === null ? '' : $classifierLabel.' ').$itemCode);

        return $code.' — '.trim($itemName);
    }

    public function familyHeading(
        PublicIndexFamilyDescriptor $family,
        string $itemCode,
        string $itemName,
        ?string $classifierLabel,
    ): string {
        if ($family->code === PublicIndexFamilyRegistry::CONSUMER_PRICES) {
            return 'Индекс потребительских цен на '.$this->lowerFirst(trim($itemName));
        }

        return $this->heading($itemCode, $itemName, $classifierLabel);
    }

    public function familyDetailTitle(
        PublicIndexFamilyDescriptor $family,
        string $itemCode,
        string $itemName,
        string $indicatorType,
        ?string $classifierLabel,
    ): string {
        if ($family->code === PublicIndexFamilyRegistry::CONSUMER_PRICES) {
            return $this->truncate($this->familyHeading($family, $itemCode, $itemName, null).' — Росстат | ПРИЗМА', 95);
        }

        return $this->detailTitle($itemCode, $itemName, $indicatorType, $classifierLabel);
    }

    public function familyDescription(
        PublicIndexFamilyDescriptor $family,
        string $itemName,
        DateTimeInterface $from,
        DateTimeInterface $to,
        string $changePercent,
        string $coefficient,
        string $provider,
    ): string {
        if ($family->code === PublicIndexFamilyRegistry::CONSUMER_PRICES) {
            return $this->truncate(sprintf(
                'Индекс потребительских цен на %s: динамика с %s по %s, изменение %s и коэффициент %s. Официальные данные %s.',
                $this->lowerFirst(trim($itemName)),
                $this->periodGenitive($from),
                $this->period($to),
                $changePercent,
                $coefficient,
                $provider,
            ), 190);
        }

        return $this->description($itemName, $from, $to, $changePercent, $coefficient, $provider);
    }

    public function classifierLabel(string $classifierCode, ?string $providerCodeKind): ?string
    {
        if ($providerCodeKind === 'rosstat_local_ag') {
            return null;
        }

        return in_array($classifierCode, ['okpd2', 'okpd2_based'], true) ? 'ОКПД2' : null;
    }

    public function indicatorType(string $indicatorName, string $provider): string
    {
        $indicatorName = trim($indicatorName) ?: 'Индекс цен производителей';
        $provider = trim($provider);
        if ($provider === 'Росстат') {
            $provider = 'Росстата';
        }

        return $provider === '' || mb_stripos($indicatorName, $provider, 0, 'UTF-8') !== false
            ? $indicatorName
            : $indicatorName.' '.$provider;
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
            '%s — индекс цен производителей: изменение %s за период с %s по %s, коэффициент %s. Данные %s.',
            trim($itemName),
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
