<?php

namespace App\Domain\PriceIndices\Domain\Enums;

enum StatisticalImportIssueSeverity: string
{
    case Fatal = 'fatal';
    case Warning = 'warning';
    case Informational = 'informational';
}
