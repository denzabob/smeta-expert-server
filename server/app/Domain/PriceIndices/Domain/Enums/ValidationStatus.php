<?php

namespace App\Domain\PriceIndices\Domain\Enums;

enum ValidationStatus: string
{
    case Passed = 'passed';
    case Warning = 'warning';
}
