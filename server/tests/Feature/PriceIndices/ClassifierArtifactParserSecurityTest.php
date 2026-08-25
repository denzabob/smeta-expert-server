<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Infrastructure\Parsing\ZipEntryNamePolicy;
use Tests\Feature\PriceIndices\Support\ClassifierParserTestCase;

class ClassifierArtifactParserSecurityTest extends ClassifierParserTestCase
{
    public function test_outer_zip_rejects_corruption_traversal_absolute_and_null_byte_names(): void
    {
        $this->assertParserError(
            'unreadable_zip',
            fn () => $this->parser()->parse(
                $this->storeSyntheticArtifact($this->makeRawOkpd2File("PK\x03\x04corrupt")),
                $this->syntheticExpectedProfile(),
            ),
        );

        foreach (['../evil.docx', '..\\evil.docx', '/absolute.docx', 'C:\\absolute.docx'] as $name) {
            $source = $this->storeSyntheticArtifact($this->makeSyntheticOkpd2Artifact(
                outerOptions: ['entries' => [$name => 'payload']],
            ));
            $this->assertParserError(
                'unsafe_zip_entry_path',
                fn () => $this->parser()->parse($source, $this->syntheticExpectedProfile()),
            );
        }

        $this->assertParserError(
            'unsafe_zip_entry_path',
            fn () => app(ZipEntryNamePolicy::class)->assertSafe("unsafe\0name.docx"),
        );
    }

    public function test_outer_zip_enforces_entry_count_sizes_and_compression_ratio(): void
    {
        config()->set('price_indices.classifier_parsers.okpd2_rosstat_docx.outer_zip.max_entries', 1);
        $this->assertParserError(
            'zip_entry_count_limit',
            fn () => $this->parseDefault(),
        );

        config()->set('price_indices.classifier_parsers.okpd2_rosstat_docx.outer_zip.max_entries', 8);
        config()->set('price_indices.classifier_parsers.okpd2_rosstat_docx.outer_zip.max_single_entry_uncompressed_bytes', 10);
        $this->assertParserError(
            'zip_entry_size_limit',
            fn () => $this->parseDefault(),
        );

        config()->set('price_indices.classifier_parsers.okpd2_rosstat_docx.outer_zip.max_single_entry_uncompressed_bytes', 20_971_520);
        config()->set('price_indices.classifier_parsers.okpd2_rosstat_docx.outer_zip.max_total_uncompressed_bytes', 100);
        $this->assertParserError(
            'zip_total_size_limit',
            fn () => $this->parseDefault(),
        );

        config()->set('price_indices.classifier_parsers.okpd2_rosstat_docx.outer_zip.max_total_uncompressed_bytes', 41_943_040);
        config()->set('price_indices.classifier_parsers.okpd2_rosstat_docx.outer_zip.max_compression_ratio', 1);
        $this->assertParserError(
            'zip_compression_ratio_limit',
            fn () => $this->parseDefault(),
        );
    }

    public function test_encrypted_duplicate_symlink_and_dangerous_outer_entries_are_rejected(): void
    {
        $encrypted = $this->makeSyntheticOkpd2Artifact(outerOptions: [
            'encrypted' => ['TIZ_OKPD2_1.docx' => 'secret'],
        ]);
        $this->assertParserError(
            'encrypted_zip_entry',
            fn () => $this->parser()->parse(
                $this->storeSyntheticArtifact($encrypted),
                $this->syntheticExpectedProfile(),
            ),
        );

        $duplicate = $this->makeSyntheticOkpd2Artifact(outerOptions: [
            'entries' => ['tiz_okpd2_1.docx' => 'duplicate'],
        ]);
        $this->assertParserError(
            'duplicate_zip_entry',
            fn () => $this->parser()->parse(
                $this->storeSyntheticArtifact($duplicate),
                $this->syntheticExpectedProfile(),
            ),
        );

        $symlink = $this->makeSyntheticOkpd2Artifact(outerOptions: [
            'symlinks' => ['linked.docx' => 'TIZ_OKPD2_1.docx'],
        ]);
        $this->assertParserError(
            'special_zip_entry',
            fn () => $this->parser()->parse(
                $this->storeSyntheticArtifact($symlink),
                $this->syntheticExpectedProfile(),
            ),
        );

        $dangerous = $this->makeSyntheticOkpd2Artifact(outerOptions: [
            'entries' => ['payload.exe' => 'binary'],
        ]);
        $this->assertParserError(
            'unexpected_outer_zip_entry',
            fn () => $this->parser()->parse(
                $this->storeSyntheticArtifact($dangerous),
                $this->syntheticExpectedProfile(),
            ),
        );
    }

    public function test_outer_zip_requires_both_parts_in_descriptor_order(): void
    {
        $partOne = $this->makeSyntheticOkpd2Docx($this->defaultOkpd2PartOneRows());
        $partTwo = $this->makeSyntheticOkpd2Docx($this->defaultOkpd2PartTwoRows());
        $missing = $this->makeSyntheticOkpd2Artifact(outerOptions: [
            'parts' => ['TIZ_OKPD2_1.docx' => $partOne],
        ]);
        $this->assertParserError(
            'incompatible_outer_zip_layout',
            fn () => $this->parser()->parse(
                $this->storeSyntheticArtifact($missing),
                $this->syntheticExpectedProfile(),
            ),
        );

        $reversed = $this->makeSyntheticOkpd2Artifact(outerOptions: [
            'parts' => [
                'TIZ_OKPD2_2.docx' => $partTwo,
                'TIZ_OKPD2_1.docx' => $partOne,
            ],
        ]);
        $this->assertParserError(
            'incompatible_outer_zip_layout',
            fn () => $this->parser()->parse(
                $this->storeSyntheticArtifact($reversed),
                $this->syntheticExpectedProfile(),
            ),
        );
    }

    public function test_docx_requires_expected_parts_and_rejects_corruption_and_unsafe_content(): void
    {
        foreach (
            [
                [['omit_document_xml' => true], 'required_docx_part_missing'],
                [['macro_content_type' => true], 'docx_macros_not_allowed'],
                [['entries' => ['word/embeddings/oleObject1.bin' => 'ole']], 'docx_embedded_content_not_allowed'],
                [['external_relationship' => true], 'docx_external_relationship_not_allowed'],
            ] as [$options, $error]
        ) {
            $source = $this->storeSyntheticArtifact($this->makeSyntheticOkpd2Artifact(partOneOptions: $options));
            $this->assertParserError(
                $error,
                fn () => $this->parser()->parse($source, $this->syntheticExpectedProfile()),
            );
        }

        $corruptedDocx = $this->makeRawOkpd2File("PK\x03\x04corrupt-docx");
        $validPartTwo = $this->makeSyntheticOkpd2Docx($this->defaultOkpd2PartTwoRows());
        $outer = $this->makeSyntheticOkpd2Artifact(outerOptions: [
            'parts' => [
                'TIZ_OKPD2_1.docx' => $corruptedDocx,
                'TIZ_OKPD2_2.docx' => $validPartTwo,
            ],
        ]);
        $this->assertParserError(
            'unreadable_zip',
            fn () => $this->parser()->parse(
                $this->storeSyntheticArtifact($outer),
                $this->syntheticExpectedProfile(),
            ),
        );
    }

    public function test_docx_zip_enforces_nested_archive_limits_paths_and_encryption(): void
    {
        config()->set('price_indices.classifier_parsers.okpd2_rosstat_docx.docx_zip.max_entries', 3);
        $this->assertParserError('zip_entry_count_limit', fn () => $this->parseDefault());

        config()->set('price_indices.classifier_parsers.okpd2_rosstat_docx.docx_zip.max_entries', 256);
        config()->set('price_indices.classifier_parsers.okpd2_rosstat_docx.docx_zip.max_total_uncompressed_bytes', 100);
        $this->assertParserError('zip_total_size_limit', fn () => $this->parseDefault());

        config()->set('price_indices.classifier_parsers.okpd2_rosstat_docx.docx_zip.max_total_uncompressed_bytes', 134_217_728);
        config()->set('price_indices.classifier_parsers.okpd2_rosstat_docx.docx_zip.max_compression_ratio', 1);
        $this->assertParserError('zip_compression_ratio_limit', fn () => $this->parseDefault());

        config()->set('price_indices.classifier_parsers.okpd2_rosstat_docx.docx_zip.max_compression_ratio', 200);
        $traversal = $this->storeSyntheticArtifact($this->makeSyntheticOkpd2Artifact(
            partOneOptions: ['entries' => ['../unsafe.xml' => 'unsafe']],
        ));
        $this->assertParserError(
            'unsafe_zip_entry_path',
            fn () => $this->parser()->parse($traversal, $this->syntheticExpectedProfile()),
        );

        $encrypted = $this->storeSyntheticArtifact($this->makeSyntheticOkpd2Artifact(
            partOneOptions: ['encrypted' => ['word/document.xml' => 'secret']],
        ));
        $this->assertParserError(
            'encrypted_zip_entry',
            fn () => $this->parser()->parse($encrypted, $this->syntheticExpectedProfile()),
        );
    }

    public function test_xml_rejects_malformed_dtd_xinclude_and_excessive_content(): void
    {
        $malformed = '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>';
        $this->assertDocumentXmlError($malformed, 'malformed_xml');

        $dtd = '<?xml version="1.0"?><!DOCTYPE w:document [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>&xxe;</w:body></w:document>';
        $this->assertDocumentXmlError($dtd, 'unsafe_xml_declaration');

        $xinclude = '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:xi="http://www.w3.org/2001/XInclude">'
            .'<w:body><xi:include href="https://example.test/external"/></w:body></w:document>';
        $this->assertDocumentXmlError($xinclude, 'unsafe_xml_declaration');

        config()->set('price_indices.classifier_parsers.okpd2_rosstat_docx.max_document_xml_bytes', 10);
        $this->assertParserError(
            'xml_size_limit',
            fn () => $this->parseDefault(),
        );
    }

    public function test_unknown_table_pattern_and_missing_header_fail_closed(): void
    {
        foreach ([['table_style' => 'OtherStyle'], ['omit_empty_header' => true]] as $options) {
            $source = $this->storeSyntheticArtifact($this->makeSyntheticOkpd2Artifact(partOneOptions: $options));
            $this->assertParserError(
                'unknown_wordprocessingml_layout',
                fn () => $this->parser()->parse($source, $this->syntheticExpectedProfile()),
            );
        }
    }

    public function test_stored_artifact_hash_and_size_are_reverified_before_parsing(): void
    {
        $source = $this->storeSyntheticArtifact($this->makeSyntheticOkpd2Artifact());
        $source->sha256 = str_repeat('0', 64);

        $this->assertParserError(
            'source_artifact_integrity_failure',
            fn () => $this->parser()->parse($source, $this->syntheticExpectedProfile()),
        );
    }

    private function parseDefault(): void
    {
        $this->parser()->parse(
            $this->storeSyntheticArtifact($this->makeSyntheticOkpd2Artifact()),
            $this->syntheticExpectedProfile(),
        );
    }

    private function assertDocumentXmlError(string $xml, string $error): void
    {
        $source = $this->storeSyntheticArtifact($this->makeSyntheticOkpd2Artifact(
            partOneOptions: ['document_xml' => $xml],
        ));
        $this->assertParserError(
            $error,
            fn () => $this->parser()->parse($source, $this->syntheticExpectedProfile()),
        );
    }
}
