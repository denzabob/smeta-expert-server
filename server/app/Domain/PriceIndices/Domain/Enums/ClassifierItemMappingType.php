<?php

namespace App\Domain\PriceIndices\Domain\Enums;

enum ClassifierItemMappingType: string
{
    case Exact = 'exact';
    case ParentAggregate = 'parent_aggregate';
    case LocalRosstat = 'local_rosstat';
    case Ambiguous = 'ambiguous';
    case Unmapped = 'unmapped';
}
