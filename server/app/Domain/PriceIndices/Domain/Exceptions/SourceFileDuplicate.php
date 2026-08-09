<?php

namespace App\Domain\PriceIndices\Domain\Exceptions;

use App\Domain\PriceIndices\Domain\SourceFiles\StatisticalSourceFile;
use DomainException;
use Throwable;

class SourceFileDuplicate extends DomainException
{
    public function __construct(
        public readonly StatisticalSourceFile $existingFile,
        ?Throwable $previous = null
    ) {
        parent::__construct('This source file already exists for the dataset.', 0, $previous);
    }
}
