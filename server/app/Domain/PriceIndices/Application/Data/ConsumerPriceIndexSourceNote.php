<?php

namespace App\Domain\PriceIndices\Application\Data;

final readonly class ConsumerPriceIndexSourceNote
{
    public function __construct(
        public string $code,
        public string $text,
        public string $sheetName,
        public string $sourceCellAddress,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'text' => $this->text,
            'sheet' => $this->sheetName,
            'cell' => $this->sourceCellAddress,
        ];
    }
}
