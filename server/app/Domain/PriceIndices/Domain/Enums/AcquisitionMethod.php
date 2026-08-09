<?php

namespace App\Domain\PriceIndices\Domain\Enums;

enum AcquisitionMethod: string
{
    case ManualUpload = 'manual_upload';
    case AutomaticUrlCheck = 'automatic_url_check';
}
