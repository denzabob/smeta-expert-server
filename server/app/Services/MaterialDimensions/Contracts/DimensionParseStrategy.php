<?php

namespace App\Services\MaterialDimensions\Contracts;

use App\Services\MaterialDimensions\DimensionParseContext;
use App\Services\MaterialDimensions\DimensionParseResult;

interface DimensionParseStrategy
{
    public function name(): string;

    public function apply(DimensionParseContext $context): ?DimensionParseResult;
}
