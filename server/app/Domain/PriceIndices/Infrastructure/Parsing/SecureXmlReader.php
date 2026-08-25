<?php

namespace App\Domain\PriceIndices\Infrastructure\Parsing;

use App\Domain\PriceIndices\Domain\Exceptions\ClassifierParserException;
use Throwable;
use XMLReader;

class SecureXmlReader
{
    private const XINCLUDE_NAMESPACE = 'http://www.w3.org/2001/XInclude';

    /** @param callable(XMLReader): void $onNode */
    public function read(string $absolutePath, int $maxBytes, callable $onNode): void
    {
        $size = is_file($absolutePath) ? filesize($absolutePath) : false;

        if ($size === false || $size < 1 || $size > $maxBytes) {
            throw ClassifierParserException::fatal(
                'xml_size_limit',
                'A required WordprocessingML part is empty or exceeds its size limit.'
            );
        }

        $previousInternalErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = new XMLReader;

        try {
            if (! $reader->open($absolutePath, null, LIBXML_NONET | LIBXML_COMPACT)) {
                throw ClassifierParserException::fatal(
                    'malformed_xml',
                    'A required WordprocessingML part cannot be opened.'
                );
            }

            $reader->setParserProperty(XMLReader::LOADDTD, false);
            $reader->setParserProperty(XMLReader::SUBST_ENTITIES, false);
            $reader->setParserProperty(XMLReader::VALIDATE, false);

            while ($reader->read()) {
                if (in_array($reader->nodeType, [XMLReader::DOC_TYPE, XMLReader::ENTITY, XMLReader::ENTITY_REF], true)) {
                    throw ClassifierParserException::fatal(
                        'unsafe_xml_declaration',
                        'DTD and entity declarations are not accepted in WordprocessingML.'
                    );
                }

                if ($reader->nodeType === XMLReader::ELEMENT
                    && $reader->namespaceURI === self::XINCLUDE_NAMESPACE
                ) {
                    throw ClassifierParserException::fatal(
                        'unsafe_xml_declaration',
                        'XInclude declarations are not accepted in WordprocessingML.'
                    );
                }

                $onNode($reader);
            }

            if (libxml_get_errors() !== []) {
                throw ClassifierParserException::fatal(
                    'malformed_xml',
                    'A required WordprocessingML part is malformed.'
                );
            }
        } catch (ClassifierParserException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw ClassifierParserException::fatal(
                'malformed_xml',
                'A required WordprocessingML part could not be parsed.',
                previous: $exception,
            );
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previousInternalErrors);
        }
    }
}
