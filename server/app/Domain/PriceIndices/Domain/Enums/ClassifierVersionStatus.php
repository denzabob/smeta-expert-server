<?php

namespace App\Domain\PriceIndices\Domain\Enums;

enum ClassifierVersionStatus: string
{
    case Ready = 'ready';
    case Scheduled = 'scheduled';
    case Superseded = 'superseded';
}
