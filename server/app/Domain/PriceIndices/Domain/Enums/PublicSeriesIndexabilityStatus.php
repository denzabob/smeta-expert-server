<?php

namespace App\Domain\PriceIndices\Domain\Enums;

enum PublicSeriesIndexabilityStatus: string
{
    case Indexable = 'indexable';
    case InsufficientHistory = 'insufficient_history';
    case IncompleteChain = 'incomplete_chain';
    case UnsupportedSeries = 'unsupported_series';
    case InvalidMetadata = 'invalid_metadata';
    case CalculationError = 'calculation_error';
    case SlugCollision = 'slug_collision';
    case NotInActivePublication = 'not_in_active_publication';
}
