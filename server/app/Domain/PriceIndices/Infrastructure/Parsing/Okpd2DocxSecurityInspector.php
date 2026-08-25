<?php

namespace App\Domain\PriceIndices\Infrastructure\Parsing;

use App\Domain\PriceIndices\Domain\Exceptions\ClassifierParserException;
use XMLReader;

class Okpd2DocxSecurityInspector
{
    private const CONTENT_TYPES = '[Content_Types].xml';

    private const ROOT_RELATIONSHIPS = '_rels/.rels';

    private const DOCUMENT_RELATIONSHIPS = 'word/_rels/document.xml.rels';

    private const DOCUMENT_XML = 'word/document.xml';

    /** @var list<string> */
    private const EXECUTABLE_EXTENSIONS = [
        'exe', 'dll', 'com', 'bat', 'cmd', 'ps1', 'scr', 'msi', 'jar', 'vbs', 'js', 'jse',
    ];

    public function __construct(private readonly SecureXmlReader $xml) {}

    public function inspect(InspectedZipArchive $docx, int $maxControlXmlBytes): void
    {
        foreach ([self::CONTENT_TYPES, self::ROOT_RELATIONSHIPS, self::DOCUMENT_RELATIONSHIPS, self::DOCUMENT_XML] as $required) {
            if (! $docx->has($required)) {
                throw ClassifierParserException::fatal(
                    'required_docx_part_missing',
                    'The DOCX is missing required WordprocessingML content.'
                );
            }
        }

        foreach ($docx->entries as $entry) {
            $name = strtolower(str_replace('\\', '/', $entry->name));
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if (str_ends_with($name, 'vbaproject.bin')) {
                throw ClassifierParserException::fatal(
                    'docx_macros_not_allowed',
                    'Macro-enabled DOCX content is not accepted.'
                );
            }

            if (in_array($extension, self::EXECUTABLE_EXTENSIONS, true)
                || str_starts_with($name, 'word/embeddings/')
                || str_starts_with($name, 'word/activex/')
            ) {
                throw ClassifierParserException::fatal(
                    'docx_embedded_content_not_allowed',
                    'Embedded executable or OLE content is not accepted in DOCX.'
                );
            }
        }

        $this->inspectContentTypes($docx, $maxControlXmlBytes);
        $this->inspectRelationships($docx, self::ROOT_RELATIONSHIPS, $maxControlXmlBytes);
        $this->inspectRelationships($docx, self::DOCUMENT_RELATIONSHIPS, $maxControlXmlBytes);
    }

    private function inspectContentTypes(InspectedZipArchive $docx, int $maxBytes): void
    {
        $temporary = $docx->materialize(self::CONTENT_TYPES, 'okpd2_ct_');

        try {
            $this->xml->read($temporary->path, $maxBytes, function (XMLReader $reader): void {
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    return;
                }

                $contentType = $reader->getAttribute('ContentType');

                if (is_string($contentType)
                    && preg_match('/macroEnabled|vba/i', $contentType) === 1
                ) {
                    throw ClassifierParserException::fatal(
                        'docx_macros_not_allowed',
                        'Macro-enabled DOCX content is not accepted.'
                    );
                }
            });
        } finally {
            $temporary->close();
        }
    }

    private function inspectRelationships(InspectedZipArchive $docx, string $entry, int $maxBytes): void
    {
        $temporary = $docx->materialize($entry, 'okpd2_rel_');

        try {
            $this->xml->read($temporary->path, $maxBytes, function (XMLReader $reader): void {
                if ($reader->nodeType === XMLReader::ELEMENT
                    && $reader->localName === 'Relationship'
                    && strcasecmp((string) $reader->getAttribute('TargetMode'), 'External') === 0
                ) {
                    throw ClassifierParserException::fatal(
                        'docx_external_relationship_not_allowed',
                        'External DOCX relationships are not accepted.'
                    );
                }
            });
        } finally {
            $temporary->close();
        }
    }
}
