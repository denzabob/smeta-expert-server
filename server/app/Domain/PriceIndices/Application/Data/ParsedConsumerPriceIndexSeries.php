<?php

namespace App\Domain\PriceIndices\Application\Data;

final readonly class ParsedConsumerPriceIndexSeries
{
    /** @param list<ParsedConsumerPriceIndexObservation> $observations */
    public function __construct(
        public string $internalKey,
        public string $name,
        public string $sheetName,
        public array $observations,
    ) {}

    public function firstPeriod(): ?string
    {
        return $this->observations === [] ? null : $this->observations[0]->periodStart;
    }

    public function lastPeriod(): ?string
    {
        $index = array_key_last($this->observations);

        return $index === null ? null : $this->observations[$index]->periodStart;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'internal_key' => $this->internalKey,
            'name' => $this->name,
            'sheet' => $this->sheetName,
            'observations' => array_map(
                fn (ParsedConsumerPriceIndexObservation $observation): array => $observation->toArray(),
                $this->observations,
            ),
        ];
    }
}
