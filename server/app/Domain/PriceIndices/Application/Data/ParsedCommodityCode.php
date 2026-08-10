<?php

namespace App\Domain\PriceIndices\Application\Data;

use App\Domain\PriceIndices\Domain\Enums\CommodityCodeKind;

final readonly class ParsedCommodityCode
{
    public function __construct(
        public string $rawCode,
        public string $normalizedCode,
        public CommodityCodeKind $codeKind,
    ) {
    }

    /** @return array{raw_code:string,normalized_code:string,code_kind:string} */
    public function toArray(): array
    {
        return [
            'raw_code' => $this->rawCode,
            'normalized_code' => $this->normalizedCode,
            'code_kind' => $this->codeKind->value,
        ];
    }
}
