<?php

namespace App\Domain\PriceIndices\Infrastructure\Import;

use App\Domain\PriceIndices\Application\Data\ParsedCommodityCode;
use App\Domain\PriceIndices\Domain\Enums\CommodityCodeKind;

final class CommodityCodeParser
{
    public function parse(mixed $value): ?ParsedCommodityCode
    {
        $rawCode = (string) $value;
        $code = preg_replace('/[\x{00A0}\x{202F}]/u', ' ', $rawCode);
        $code = preg_replace('/\s+/u', ' ', $code ?? '');
        $code = trim($code ?? '');

        if (preg_match('/^(\d{2}(?:\.\d+)+)(?:\.(АГ))?$/iu', $code, $matches) !== 1) {
            return null;
        }

        $hasRosstatSuffix = isset($matches[2]) && $matches[2] !== '';
        $normalizedCode = $matches[1].($hasRosstatSuffix ? '.'.mb_strtoupper($matches[2], 'UTF-8') : '');

        return new ParsedCommodityCode(
            $rawCode,
            $normalizedCode,
            $hasRosstatSuffix ? CommodityCodeKind::RosstatLocalAg : CommodityCodeKind::Numeric,
        );
    }
}
