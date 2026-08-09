<?php

namespace App\Domain\PriceIndices\Domain\Enums;

enum SourceFileStatus: string
{
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Active = 'active';
    case Rejected = 'rejected';
    case Superseded = 'superseded';
}
