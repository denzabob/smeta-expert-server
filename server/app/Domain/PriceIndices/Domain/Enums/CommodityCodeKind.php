<?php

namespace App\Domain\PriceIndices\Domain\Enums;

enum CommodityCodeKind: string
{
    case Numeric = 'numeric';
    case RosstatLocalAg = 'rosstat_local_ag';
}
