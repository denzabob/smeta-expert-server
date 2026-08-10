<?php

namespace App\Domain\PriceIndices\Domain\Enums;

enum StatisticalImportPreviewStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Ready = 'ready';
    case Failed = 'failed';
    case Expired = 'expired';
}
