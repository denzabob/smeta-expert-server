<?php

namespace App\Domain\PriceIndices\Domain\Enums;

enum SourceCheckStatus: string
{
    case Running = 'running';
    case NotFound = 'not_found';
    case Unchanged = 'unchanged';
    case Discovered = 'discovered';
    case Downloaded = 'downloaded';
    case Rejected = 'rejected';
    case Failed = 'failed';
}
