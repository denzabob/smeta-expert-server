<?php

namespace App\Domain\PriceIndices\Application\Data;

use JsonSerializable;

/** @implements JsonSerializable<array<string, mixed>> */
final readonly class PublicPriceIndexChartData implements JsonSerializable
{
    /**
     * @param  array{slug: string, title: string, code: ?string, family: string}  $series
     * @param  list<array{period: string, display_period: string, value: ?string, sequence: int}>  $points
     * @param  array{first_available_period: ?string, last_available_period: ?string, calculator_max_range_months: int}  $limits
     */
    public function __construct(
        public array $series,
        public array $points,
        public array $limits,
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'series' => $this->series,
            'points' => $this->points,
            'limits' => $this->limits,
        ];
    }
}
