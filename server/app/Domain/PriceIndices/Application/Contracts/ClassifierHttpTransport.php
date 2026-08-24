<?php

namespace App\Domain\PriceIndices\Application\Contracts;

use App\Domain\PriceIndices\Application\Data\ClassifierHttpResponse;
use App\Domain\PriceIndices\Application\Data\TrustedClassifierDescriptor;

interface ClassifierHttpTransport
{
    public function get(string $url, TrustedClassifierDescriptor $descriptor): ClassifierHttpResponse;
}
