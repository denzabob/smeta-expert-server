<?php

namespace App\Domain\PriceIndices\Infrastructure\Import;

use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

class XlsxChunkReadFilter implements IReadFilter
{
    public function __construct(
        private readonly int $startRow,
        private readonly int $endRow,
        private readonly int $headerRows = 10,
        private readonly int $maxColumnIndex = 20,
    ) {
    }

    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
    {
        $column = 0;
        foreach (str_split($columnAddress) as $character) {
            $column = ($column * 26) + (ord($character) - 64);
        }

        return $column <= $this->maxColumnIndex
            && ($row <= $this->headerRows || ($row >= $this->startRow && $row <= $this->endRow));
    }
}
