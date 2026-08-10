<?php

namespace App\Domain\PriceIndices\Domain\Enums;

enum StatisticalImportStatus: string
{
    case Pending = 'pending';
    case Importing = 'importing';
    case Validating = 'validating';
    case ReadyForPublish = 'ready_for_publish';
    case Published = 'published';
    case Superseded = 'superseded';
    case Failed = 'failed';
}
