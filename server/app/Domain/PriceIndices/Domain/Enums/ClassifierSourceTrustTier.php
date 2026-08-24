<?php

namespace App\Domain\PriceIndices\Domain\Enums;

enum ClassifierSourceTrustTier: string
{
    case OfficialAuthoritative = 'official_authoritative';
    case OperatorOfficialUpload = 'operator_official_upload';
    case ReferenceFixture = 'reference_fixture';
}
