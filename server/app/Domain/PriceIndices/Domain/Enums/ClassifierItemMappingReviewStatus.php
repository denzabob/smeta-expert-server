<?php

namespace App\Domain\PriceIndices\Domain\Enums;

enum ClassifierItemMappingReviewStatus: string
{
    case Proposed = 'proposed';
    case NeedsReview = 'needs_review';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
}
