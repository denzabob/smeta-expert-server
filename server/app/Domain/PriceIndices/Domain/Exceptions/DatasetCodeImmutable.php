<?php

namespace App\Domain\PriceIndices\Domain\Exceptions;

use DomainException;

class DatasetCodeImmutable extends DomainException
{
    public function __construct()
    {
        parent::__construct('Dataset code cannot be changed after a source file has been stored.');
    }
}
