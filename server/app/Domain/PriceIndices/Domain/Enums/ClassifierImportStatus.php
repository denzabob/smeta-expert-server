<?php

namespace App\Domain\PriceIndices\Domain\Enums;

enum ClassifierImportStatus: string
{
    case Pending = 'pending';
    case Parsing = 'parsing';
    case Validating = 'validating';
    case Ready = 'ready';
    case Failed = 'failed';
}
