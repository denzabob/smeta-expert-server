<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Domain\Enums\SourceFileErrorCode;
use App\Domain\PriceIndices\Domain\Enums\ValidationStatus;
use App\Domain\PriceIndices\Domain\Exceptions\XlsxValidationException;
use App\Domain\PriceIndices\Infrastructure\Storage\XlsxTechnicalValidator;
use Illuminate\Support\Facades\Config;
use Tests\Feature\PriceIndices\Support\BuildsSyntheticXlsx;
use Tests\TestCase;

class PriceIndicesXlsxTechnicalValidatorTest extends TestCase
{
    use BuildsSyntheticXlsx;

    protected function tearDown(): void
    {
        $this->forgetSyntheticXlsxFiles();
        parent::tearDown();
    }

    public function test_valid_minimal_xlsx_passes_without_reading_workbook_values(): void
    {
        $result = $this->validator()->validate(
            $this->makeSyntheticXlsx(),
            'indices.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );

        $this->assertSame(ValidationStatus::Passed, $result->status);
        $this->assertSame([], $result->warnings);
    }

    public function test_generic_allowed_mime_produces_warning(): void
    {
        $result = $this->validator()->validate(
            $this->makeSyntheticXlsx(),
            'indices.xlsx',
            'application/octet-stream'
        );

        $this->assertSame(ValidationStatus::Warning, $result->status);
        $this->assertSame(['generic_mime_type'], $result->warnings);
    }

    public function test_missing_workbook_is_rejected(): void
    {
        $this->assertValidationCode(
            SourceFileErrorCode::MissingWorkbook,
            $this->makeSyntheticXlsx(includeWorkbook: false)
        );
    }

    public function test_missing_content_types_is_rejected(): void
    {
        $this->assertValidationCode(
            SourceFileErrorCode::MissingContentTypes,
            $this->makeSyntheticXlsx(includeContentTypes: false)
        );
    }

    public function test_invalid_zip_and_renamed_non_zip_are_rejected(): void
    {
        $corruptZip = tempnam(sys_get_temp_dir(), 'price_indices_corrupt_');
        $plainFile = tempnam(sys_get_temp_dir(), 'price_indices_plain_');
        file_put_contents($corruptZip, "PK\x03\x04corrupt");
        file_put_contents($plainFile, 'not a ZIP archive');
        $this->syntheticXlsxPaths[] = $corruptZip;
        $this->syntheticXlsxPaths[] = $plainFile;

        $this->assertValidationCode(SourceFileErrorCode::InvalidZip, $corruptZip);
        $this->assertValidationCode(SourceFileErrorCode::InvalidMime, $plainFile);
    }

    public function test_zero_byte_file_is_rejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'price_indices_empty_');
        $this->syntheticXlsxPaths[] = $path;

        $this->assertValidationCode(SourceFileErrorCode::FileEmpty, $path);
    }

    public function test_macro_entry_and_macro_content_type_are_rejected(): void
    {
        $this->assertValidationCode(
            SourceFileErrorCode::MacrosNotAllowed,
            $this->makeSyntheticXlsx(['xl/vbaProject.bin' => 'macro'])
        );
        $this->assertValidationCode(
            SourceFileErrorCode::MacrosNotAllowed,
            $this->makeSyntheticXlsx(macroContentType: true)
        );
    }

    public function test_traversal_and_absolute_entry_names_are_rejected(): void
    {
        foreach (['../evil.txt', '..\\evil.txt', '/absolute/evil.txt', 'C:\\evil.txt'] as $entry) {
            $this->assertValidationCode(
                SourceFileErrorCode::PathTraversal,
                $this->makeSyntheticXlsx([$entry => 'unsafe'])
            );
        }
    }

    public function test_zip_entry_count_limit_is_enforced(): void
    {
        Config::set('price_indices.xlsx.max_zip_entries', 2);

        $this->assertValidationCode(
            SourceFileErrorCode::TooManyZipEntries,
            $this->makeSyntheticXlsx(['xl/extra.xml' => '<extra/>'])
        );
    }

    public function test_single_and_total_uncompressed_limits_are_enforced(): void
    {
        Config::set('price_indices.xlsx.max_single_entry_uncompressed_bytes', 10);
        $this->assertValidationCode(SourceFileErrorCode::EntryTooLarge, $this->makeSyntheticXlsx());

        Config::set('price_indices.xlsx.max_single_entry_uncompressed_bytes', 1_000_000);
        Config::set('price_indices.xlsx.max_total_uncompressed_bytes', 100);
        $this->assertValidationCode(SourceFileErrorCode::UncompressedSizeLimit, $this->makeSyntheticXlsx());
    }

    public function test_compression_ratio_limit_is_enforced_but_typical_xml_passes_default(): void
    {
        $path = $this->makeSyntheticXlsx([
            'xl/worksheets/sheet1.xml' => '<sheetData>'.str_repeat('<row><c>123</c></row>', 100).'</sheetData>',
        ]);

        $this->validator()->validate($path, 'indices.xlsx', 'application/zip');
        $this->addToAssertionCount(1);

        Config::set('price_indices.xlsx.max_compression_ratio', 1);
        $this->assertValidationCode(SourceFileErrorCode::CompressionRatioLimit, $path);
    }

    public function test_executable_and_embedded_entries_are_rejected(): void
    {
        $this->assertValidationCode(
            SourceFileErrorCode::EmbeddedExecutable,
            $this->makeSyntheticXlsx(['payload.exe' => 'binary'])
        );
        $this->assertValidationCode(
            SourceFileErrorCode::EmbeddedExecutable,
            $this->makeSyntheticXlsx(['xl/embeddings/oleObject1.bin' => 'ole'])
        );
    }

    private function validator(): XlsxTechnicalValidator
    {
        return app(XlsxTechnicalValidator::class);
    }

    private function assertValidationCode(SourceFileErrorCode $expected, string $path): void
    {
        try {
            $this->validator()->validate($path, 'indices.xlsx', 'application/octet-stream');
            $this->fail("Validation code {$expected->value} was not raised.");
        } catch (XlsxValidationException $exception) {
            $this->assertSame($expected, $exception->errorCode);
        }
    }
}
