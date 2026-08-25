<?php

namespace Tests\Feature\PriceIndices\Support;

use RuntimeException;
use ZipArchive;

trait BuildsSyntheticOkpd2Artifact
{
    /** @var list<string> */
    private array $syntheticOkpd2Paths = [];

    /**
     * @param  list<array{left: string, right: string|list<string>}>|null  $partOneRows
     * @param  list<array{left: string, right: string|list<string>}>|null  $partTwoRows
     * @param  array<string, mixed>  $outerOptions
     * @param  array<string, mixed>  $partOneOptions
     * @param  array<string, mixed>  $partTwoOptions
     */
    protected function makeSyntheticOkpd2Artifact(
        ?array $partOneRows = null,
        ?array $partTwoRows = null,
        array $outerOptions = [],
        array $partOneOptions = [],
        array $partTwoOptions = [],
    ): string {
        $partOne = $this->makeSyntheticOkpd2Docx(
            $partOneRows ?? $this->defaultOkpd2PartOneRows(),
            $partOneOptions,
        );
        $partTwo = $this->makeSyntheticOkpd2Docx(
            $partTwoRows ?? $this->defaultOkpd2PartTwoRows(),
            $partTwoOptions,
        );
        $path = $this->temporaryOkpd2Path('okpd2_outer_');
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create a synthetic OKPD2 outer archive.');
        }

        $parts = $outerOptions['parts'] ?? [
            'TIZ_OKPD2_1.docx' => $partOne,
            'TIZ_OKPD2_2.docx' => $partTwo,
        ];

        foreach ($parts as $name => $sourcePath) {
            $zip->addFile($sourcePath, $name);
        }

        foreach (($outerOptions['entries'] ?? []) as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        foreach (($outerOptions['symlinks'] ?? []) as $name => $target) {
            $zip->addFromString($name, $target);
            $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0120777 << 16);
        }

        foreach (($outerOptions['encrypted'] ?? []) as $name => $password) {
            if (! $zip->setEncryptionName($name, ZipArchive::EM_AES_256, $password)) {
                throw new RuntimeException('Unable to encrypt a synthetic ZIP entry.');
            }
        }

        $zip->close();

        return $path;
    }

    /**
     * @param  list<array{left: string, right: string|list<string>}>  $rows
     * @param  array<string, mixed>  $options
     */
    protected function makeSyntheticOkpd2Docx(array $rows, array $options = []): string
    {
        $path = $this->temporaryOkpd2Path('okpd2_docx_');
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create a synthetic OKPD2 DOCX.');
        }

        if (! ($options['omit_content_types'] ?? false)) {
            $contentType = ($options['macro_content_type'] ?? false)
                ? 'application/vnd.ms-word.document.macroEnabled.main+xml'
                : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml';
            $zip->addFromString(
                '[Content_Types].xml',
                '<?xml version="1.0" encoding="UTF-8"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Override PartName="/word/document.xml" ContentType="'.$contentType.'"/>'
                .'</Types>',
            );
        }

        if (! ($options['omit_root_relationships'] ?? false)) {
            $zip->addFromString(
                '_rels/.rels',
                $this->relationshipsXml([
                    ['Id' => 'rId1', 'Type' => 'officeDocument', 'Target' => 'word/document.xml'],
                ]),
            );
        }

        if (! ($options['omit_document_relationships'] ?? false)) {
            $relationships = [];

            if ($options['external_relationship'] ?? false) {
                $relationships[] = [
                    'Id' => 'rIdExternal',
                    'Type' => 'hyperlink',
                    'Target' => 'https://example.test/external',
                    'TargetMode' => 'External',
                ];
            }

            $zip->addFromString(
                'word/_rels/document.xml.rels',
                $this->relationshipsXml($relationships),
            );
        }

        if (! ($options['omit_document_xml'] ?? false)) {
            $zip->addFromString(
                'word/document.xml',
                $options['document_xml'] ?? $this->wordDocumentXml(
                    $rows,
                    $options['table_style'] ?? 'TableGrid',
                    ! ($options['omit_empty_header'] ?? false),
                ),
            );
        }

        foreach (($options['entries'] ?? []) as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        foreach (($options['encrypted'] ?? []) as $name => $password) {
            if (! $zip->setEncryptionName($name, ZipArchive::EM_AES_256, $password)) {
                throw new RuntimeException('Unable to encrypt a synthetic DOCX entry.');
            }
        }

        $zip->close();

        return $path;
    }

    /** @return list<array{left: string, right: string|list<string>}> */
    protected function defaultOkpd2PartOneRows(): array
    {
        return [
            ['left' => 'РАЗДЕЛ A', 'right' => 'ПРОДУКЦИЯ РАЗДЕЛА A'],
            ['left' => 'РАЗДЕЛ B', 'right' => 'ПРОДУКЦИЯ РАЗДЕЛА B'],
            ['left' => 'РАЗДЕЛ C', 'right' => 'ПРОДУКЦИЯ ОБРАБАТЫВАЮЩИХ ПРОИЗВОДСТВ'],
            ['left' => '31', 'right' => 'Мебель'],
            ['left' => '31.0', 'right' => 'Мебель'],
            ['left' => '31.02', 'right' => 'Мебель кухонная'],
            ['left' => '31.02.1', 'right' => 'Мебель кухонная'],
            ['left' => '31.02.10', 'right' => 'Мебель кухонная'],
            ['left' => '31.02.10.140 ', 'right' => ['Наборы кухонной', 'мебели']],
            ['left' => '31.02.10.141', 'right' => 'Наборы кухонной мебели деревянные'],
            ['left' => 'РАЗДЕЛ D', 'right' => 'ПРОДУКЦИЯ РАЗДЕЛА D'],
        ];
    }

    /** @return list<array{left: string, right: string|list<string>}> */
    protected function defaultOkpd2PartTwoRows(): array
    {
        $rows = [];

        foreach (range('E', 'U') as $section) {
            $rows[] = ['left' => "РАЗДЕЛ {$section}", 'right' => "ПРОДУКЦИЯ РАЗДЕЛА {$section}"];

            if ($section === 'F') {
                $rows[] = ['left' => '', 'right' => 'Этот раздел также включает: строительные работы'];
            }
        }

        return $rows;
    }

    protected function forgetSyntheticOkpd2Artifacts(): void
    {
        foreach ($this->syntheticOkpd2Paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->syntheticOkpd2Paths = [];
    }

    protected function makeRawOkpd2File(string $contents): string
    {
        $path = $this->temporaryOkpd2Path('okpd2_raw_');
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * @param  list<array{left: string, right: string|list<string>}>  $rows
     */
    private function wordDocumentXml(array $rows, string $tableStyle, bool $includeEmptyHeader): string
    {
        $tableRows = $includeEmptyHeader ? $this->wordTableRow('', '') : '';

        foreach ($rows as $row) {
            $tableRows .= $this->wordTableRow($row['left'], $row['right']);
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:body>'
            .'<w:p><w:r><w:t>ОК 034-2014 (КПЕС 2008)</w:t></w:r></w:p>'
            .'<w:tbl><w:tblPr><w:tblStyle w:val="TableNormal"/></w:tblPr>'
            .$this->wordTableRow('ХХ', 'класс').'</w:tbl>'
            .'<w:tbl><w:tblPr><w:tblStyle w:val="'.$this->xml($tableStyle).'"/></w:tblPr>'
            .$tableRows.'</w:tbl>'
            .'<w:sectPr/>'
            .'</w:body></w:document>';
    }

    /** @param string|list<string> $right */
    private function wordTableRow(string $left, string|array $right): string
    {
        return '<w:tr>'.$this->wordTableCell([$left]).$this->wordTableCell((array) $right).'</w:tr>';
    }

    /** @param list<string> $paragraphs */
    private function wordTableCell(array $paragraphs): string
    {
        $xml = '<w:tc>';

        foreach ($paragraphs as $paragraph) {
            $xml .= '<w:p><w:r><w:t xml:space="preserve">'.$this->xml($paragraph).'</w:t></w:r></w:p>';
        }

        return $xml.'</w:tc>';
    }

    /** @param list<array<string, string>> $relationships */
    private function relationshipsXml(array $relationships): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';

        foreach ($relationships as $relationship) {
            $attributes = '';

            foreach ($relationship as $name => $value) {
                $attributes .= ' '.$name.'="'.$this->xml($value).'"';
            }

            $xml .= '<Relationship'.$attributes.'/>';
        }

        return $xml.'</Relationships>';
    }

    private function temporaryOkpd2Path(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);

        if ($path === false) {
            throw new RuntimeException('Unable to create a synthetic OKPD2 path.');
        }

        $this->syntheticOkpd2Paths[] = $path;

        return $path;
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
