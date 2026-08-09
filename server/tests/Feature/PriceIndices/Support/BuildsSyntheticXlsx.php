<?php

namespace Tests\Feature\PriceIndices\Support;

use RuntimeException;
use ZipArchive;

trait BuildsSyntheticXlsx
{
    /** @var list<string> */
    private array $syntheticXlsxPaths = [];

    /**
     * @param array<string, string> $entries
     */
    protected function makeSyntheticXlsx(
        array $entries = [],
        bool $includeContentTypes = true,
        bool $includeWorkbook = true,
        bool $macroContentType = false,
    ): string {
        $path = tempnam(sys_get_temp_dir(), 'price_indices_xlsx_');

        if ($path === false) {
            throw new RuntimeException('Unable to create a synthetic XLSX path.');
        }

        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create a synthetic XLSX archive.');
        }

        if ($includeContentTypes) {
            $contentType = $macroContentType
                ? 'application/vnd.ms-excel.sheet.macroEnabled.main+xml'
                : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml';
            $zip->addFromString(
                '[Content_Types].xml',
                '<?xml version="1.0"?><Types><Override ContentType="'.$contentType.'"/></Types>'
            );
        }

        if ($includeWorkbook) {
            $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook/>');
        }

        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }

        $zip->close();
        $this->syntheticXlsxPaths[] = $path;

        return $path;
    }

    protected function forgetSyntheticXlsxFiles(): void
    {
        foreach ($this->syntheticXlsxPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->syntheticXlsxPaths = [];
    }
}
