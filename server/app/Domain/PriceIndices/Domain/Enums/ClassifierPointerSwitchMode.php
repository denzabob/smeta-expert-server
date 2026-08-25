<?php

namespace App\Domain\PriceIndices\Domain\Enums;

enum ClassifierPointerSwitchMode: string
{
    case Activation = 'activation';
    case Rollback = 'rollback';
}
