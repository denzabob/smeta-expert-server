<?php

namespace App\Domain\PriceIndices\Domain\Enums;

enum StatisticalObservationMissingReason: string
{
    case Blank = 'blank';
    case Ellipsis = 'ellipsis';
    case ThreeDots = 'three_dots';
    case Dash = 'dash';
    case OtherProviderMarker = 'other_provider_marker';
}
