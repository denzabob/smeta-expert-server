<?php

namespace App\Domain\PriceIndices\Domain\Enums;

enum ClassifierSemanticLevel: string
{
    case Section = 'section';
    case ClassLevel = 'class';
    case Subclass = 'subclass';
    case Group = 'group';
    case Subgroup = 'subgroup';
    case Type = 'type';
    case Category = 'category';
    case Subcategory = 'subcategory';
}
