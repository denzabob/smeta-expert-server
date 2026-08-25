<?php

namespace App\Domain\PriceIndices\Infrastructure\Parsing;

use App\Domain\PriceIndices\Domain\Enums\ClassifierSemanticLevel;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierParserException;
use XMLReader;

class Okpd2WordprocessingMlReader
{
    private const WORDPROCESSINGML_NAMESPACE = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public function __construct(private readonly SecureXmlReader $xml) {}

    /** @param list<string> $expectedSections */
    public function read(
        string $documentXmlPath,
        string $sourcePart,
        array $expectedSections,
        int $maxDocumentXmlBytes,
    ): ParsedWordPart {
        $nodes = [];
        $sections = [];
        $mainTables = 0;
        $rowsCount = 0;
        $notesCount = 0;
        $bodyDepth = null;
        $tableDepth = null;
        $tableStyle = null;
        $tableSawRowBeforeStyle = false;
        $rowDepth = null;
        $rowCells = [];
        $cellDepth = null;
        $cellParagraphs = [];
        $paragraphDepth = null;
        $paragraphText = '';
        $textDepth = null;
        $currentSection = null;
        $mainTableHeaderConsumed = false;

        $this->xml->read($documentXmlPath, $maxDocumentXmlBytes, function (XMLReader $reader) use (
            &$nodes,
            &$sections,
            &$mainTables,
            &$rowsCount,
            &$notesCount,
            &$bodyDepth,
            &$tableDepth,
            &$tableStyle,
            &$tableSawRowBeforeStyle,
            &$rowDepth,
            &$rowCells,
            &$cellDepth,
            &$cellParagraphs,
            &$paragraphDepth,
            &$paragraphText,
            &$textDepth,
            &$currentSection,
            &$mainTableHeaderConsumed,
            $sourcePart,
        ): void {
            $isWord = $reader->namespaceURI === self::WORDPROCESSINGML_NAMESPACE;

            if ($reader->nodeType === XMLReader::ELEMENT) {
                if ($isWord && $reader->localName === 'body') {
                    $bodyDepth = $reader->depth;

                    return;
                }

                if ($isWord
                    && $reader->localName === 'tbl'
                    && $bodyDepth !== null
                    && $reader->depth === $bodyDepth + 1
                ) {
                    if ($tableDepth !== null) {
                        throw $this->layoutFailure();
                    }

                    $tableDepth = $reader->depth;
                    $tableStyle = null;
                    $tableSawRowBeforeStyle = false;

                    return;
                }

                if ($tableDepth === null) {
                    return;
                }

                if ($isWord && $reader->localName === 'tblStyle') {
                    if ($tableSawRowBeforeStyle) {
                        throw $this->layoutFailure();
                    }

                    $tableStyle = $reader->getAttributeNs('val', self::WORDPROCESSINGML_NAMESPACE)
                        ?? $reader->getAttribute('w:val')
                        ?? $reader->getAttribute('val');

                    if ($tableStyle === 'TableGrid') {
                        $mainTables++;
                    }

                    return;
                }

                if ($isWord && $reader->localName === 'tr' && $reader->depth === $tableDepth + 1) {
                    if ($tableStyle === null) {
                        $tableSawRowBeforeStyle = true;
                    }

                    if ($tableStyle === 'TableGrid') {
                        $rowDepth = $reader->depth;
                        $rowCells = [];
                    }

                    return;
                }

                if ($rowDepth === null) {
                    return;
                }

                if ($isWord && $reader->localName === 'tc' && $reader->depth === $rowDepth + 1) {
                    $cellDepth = $reader->depth;
                    $cellParagraphs = [];

                    return;
                }

                if ($cellDepth === null) {
                    return;
                }

                if ($isWord && $reader->localName === 'p') {
                    $paragraphDepth = $reader->depth;
                    $paragraphText = '';

                    return;
                }

                if ($paragraphDepth !== null && $isWord && $reader->localName === 't') {
                    $textDepth = $reader->depth;

                    return;
                }

                if ($paragraphDepth !== null
                    && $isWord
                    && in_array($reader->localName, ['tab', 'br', 'cr'], true)
                ) {
                    $paragraphText .= ' ';
                }

                return;
            }

            if (in_array($reader->nodeType, [XMLReader::TEXT, XMLReader::CDATA, XMLReader::SIGNIFICANT_WHITESPACE], true)
                && $textDepth !== null
            ) {
                $paragraphText .= $reader->value;

                return;
            }

            if ($reader->nodeType !== XMLReader::END_ELEMENT || ! $isWord) {
                return;
            }

            if ($reader->localName === 't' && $textDepth === $reader->depth) {
                $textDepth = null;

                return;
            }

            if ($reader->localName === 'p' && $paragraphDepth === $reader->depth) {
                $cleaned = $this->normalizeWhitespace($paragraphText);

                if ($cleaned !== '') {
                    $cellParagraphs[] = $cleaned;
                }

                $paragraphDepth = null;
                $paragraphText = '';

                return;
            }

            if ($reader->localName === 'tc' && $cellDepth === $reader->depth) {
                $rowCells[] = $cellParagraphs;
                $cellDepth = null;
                $cellParagraphs = [];

                return;
            }

            if ($reader->localName === 'tr' && $rowDepth === $reader->depth) {
                $rowsCount++;
                $this->consumeRow(
                    cells: $rowCells,
                    rowNumber: $rowsCount,
                    sourcePart: $sourcePart,
                    headerConsumed: $mainTableHeaderConsumed,
                    currentSection: $currentSection,
                    sections: $sections,
                    nodes: $nodes,
                    notesCount: $notesCount,
                );
                $rowDepth = null;
                $rowCells = [];

                return;
            }

            if ($reader->localName === 'tbl' && $tableDepth === $reader->depth) {
                $tableDepth = null;
                $tableStyle = null;

                return;
            }

            if ($reader->localName === 'body' && $bodyDepth === $reader->depth) {
                $bodyDepth = null;
            }
        });

        if ($mainTables !== 1 || ! $mainTableHeaderConsumed || $nodes === []) {
            throw $this->layoutFailure();
        }

        if ($sections !== $expectedSections) {
            throw ClassifierParserException::fatal(
                'incompatible_docx_part_sections',
                'The DOCX part section sequence is incompatible with parser version 1.'
            );
        }

        return new ParsedWordPart($nodes, $sections, $rowsCount, $notesCount);
    }

    /**
     * @param  list<list<string>>  $cells
     * @param  list<string>  $sections
     * @param  list<RawClassifierNode>  $nodes
     */
    private function consumeRow(
        array $cells,
        int $rowNumber,
        string $sourcePart,
        bool &$headerConsumed,
        ?string &$currentSection,
        array &$sections,
        array &$nodes,
        int &$notesCount,
    ): void {
        if (count($cells) !== 2) {
            throw $this->layoutFailure();
        }

        $left = $this->normalizeWhitespace(implode(' ', $cells[0]));
        $right = $this->normalizeWhitespace(implode(' ', $cells[1]));

        if (! $headerConsumed) {
            if ($left !== '' || $right !== '') {
                throw $this->layoutFailure();
            }

            $headerConsumed = true;

            return;
        }

        if ($left === '') {
            if ($right === '' || $nodes === []) {
                throw $this->layoutFailure();
            }

            $previous = $nodes[array_key_last($nodes)];

            if ($previous->notes !== null) {
                throw ClassifierParserException::fatal(
                    'ambiguous_classifier_note',
                    'A classifier note cannot be mapped unambiguously to one node.'
                );
            }

            $previous->notes = $this->normalizeNotes($cells[1]);
            $notesCount++;

            return;
        }

        if ($right === '') {
            throw $this->layoutFailure();
        }

        if (preg_match('/^РАЗДЕЛ\s+([A-U])$/u', $left, $matches) === 1) {
            $currentSection = $matches[1];
            $sections[] = $currentSection;
            $nodes[] = new RawClassifierNode(
                code: $currentSection,
                name: $right,
                normalizedName: $this->normalizeName($right),
                semanticLevel: ClassifierSemanticLevel::Section,
                formalDepth: 0,
                sectionCode: $currentSection,
                sourcePart: $sourcePart,
                sourceRow: $rowNumber,
            );

            return;
        }

        if ($currentSection === null) {
            throw $this->layoutFailure();
        }

        $code = $this->normalizeCode($left);
        [$semanticLevel, $formalDepth] = $this->semanticLevel($code);
        $nodes[] = new RawClassifierNode(
            code: $code,
            name: $right,
            normalizedName: $this->normalizeName($right),
            semanticLevel: $semanticLevel,
            formalDepth: $formalDepth,
            sectionCode: $currentSection,
            sourcePart: $sourcePart,
            sourceRow: $rowNumber,
        );
    }

    /** @return array{ClassifierSemanticLevel, int} */
    private function semanticLevel(string $code): array
    {
        return match (true) {
            preg_match('/^\d{2}$/', $code) === 1 => [ClassifierSemanticLevel::ClassLevel, 1],
            preg_match('/^\d{2}\.\d$/', $code) === 1 => [ClassifierSemanticLevel::Subclass, 2],
            preg_match('/^\d{2}\.\d{2}$/', $code) === 1 => [ClassifierSemanticLevel::Group, 3],
            preg_match('/^\d{2}\.\d{2}\.\d$/', $code) === 1 => [ClassifierSemanticLevel::Subgroup, 4],
            preg_match('/^\d{2}\.\d{2}\.\d{2}$/', $code) === 1 => [ClassifierSemanticLevel::Type, 5],
            preg_match('/^\d{2}\.\d{2}\.\d{2}\.\d{2}0$/', $code) === 1 => [ClassifierSemanticLevel::Category, 6],
            preg_match('/^\d{2}\.\d{2}\.\d{2}\.\d{3}$/', $code) === 1 => [ClassifierSemanticLevel::Subcategory, 7],
            default => throw ClassifierParserException::fatal(
                'unknown_classifier_code_mask',
                'The classifier contains an unsupported code shape.'
            ),
        };
    }

    private function normalizeCode(string $code): string
    {
        return $this->normalizeWhitespace($code);
    }

    private function normalizeName(string $name): string
    {
        return mb_strtolower($this->normalizeWhitespace($name), 'UTF-8');
    }

    /** @param list<string> $paragraphs */
    private function normalizeNotes(array $paragraphs): string
    {
        return implode("\n", array_values(array_filter(
            array_map($this->normalizeWhitespace(...), $paragraphs),
            fn (string $paragraph): bool => $paragraph !== '',
        )));
    }

    private function normalizeWhitespace(string $value): string
    {
        return trim((string) preg_replace('/[\p{Z}\s]+/u', ' ', $value));
    }

    private function layoutFailure(): ClassifierParserException
    {
        return ClassifierParserException::fatal(
            'unknown_wordprocessingml_layout',
            'The WordprocessingML layout is incompatible with parser version 1.'
        );
    }
}
