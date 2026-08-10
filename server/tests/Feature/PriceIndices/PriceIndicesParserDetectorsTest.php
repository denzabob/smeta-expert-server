<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Infrastructure\Import\CommodityCodeParser;
use App\Domain\PriceIndices\Domain\Enums\CommodityCodeKind;
use App\Domain\PriceIndices\Infrastructure\Import\StatisticalComparisonBasisDetector;
use App\Domain\PriceIndices\Infrastructure\Import\StatisticalMonthHeaderParser;
use App\Domain\PriceIndices\Infrastructure\Import\StatisticalTerritoryDetector;
use App\Domain\PriceIndices\Infrastructure\Import\StatisticalYearDetector;
use InvalidArgumentException;
use Tests\TestCase;

class PriceIndicesParserDetectorsTest extends TestCase
{
    public function test_year_detector_accepts_single_supported_year_and_rejects_ranges(): void
    {
        $detector = app(StatisticalYearDetector::class);
        foreach (range(2021, 2026) as $year) {
            $this->assertSame($year, $detector->detect("Данные за {$year} год"));
        }
        $this->assertNull($detector->detect('Данные 2017–2025'));
        $this->assertTrue($detector->isAmbiguous('Данные 2017–2025'));
    }

    public function test_comparison_basis_is_detected_by_normalized_content(): void
    {
        $detector = app(StatisticalComparisonBasisDetector::class);
        $this->assertSame('previous_month', $detector->detect(" К\u{00A0}предыдущему   месяцу "));
        $this->assertSame('previous_december', $detector->detect('к декабрю предыдущего года'));
        $this->assertSame('year_over_year', $detector->detect('к соответствующему месяцу прошлого года'));
        $this->assertSame('year_to_date_year_over_year', $detector->detect('с начала года к соответствующему периоду прошлого года'));
        $this->assertSame('year_to_date_year_over_year', $detector->detect('за период с начала года, в % к соответствующему периоду предыдущего года'));
        $this->assertNull($detector->detect('произвольный заголовок'));
    }

    public function test_month_headers_require_unique_contiguous_order(): void
    {
        $parser = app(StatisticalMonthHeaderParser::class);
        $this->assertSame(['C' => 1, 'D' => 2, 'E' => 3], $parser->parse([
            'C' => 'Январь', 'D' => ' февраль ', 'E' => 'МАРТ1)',
        ]));

        $this->expectException(InvalidArgumentException::class);
        $parser->parse(['C' => 'январь', 'D' => 'март']);
    }

    public function test_commodity_and_territory_detectors_are_strict(): void
    {
        $codes = app(CommodityCodeParser::class);
        $territory = app(StatisticalTerritoryDetector::class);
        $numeric = $codes->parse('31.02.10.140');
        $this->assertNotNull($numeric);
        $this->assertSame('31.02.10.140', $numeric->rawCode);
        $this->assertSame('31.02.10.140', $numeric->normalizedCode);
        $this->assertSame(CommodityCodeKind::Numeric, $numeric->codeKind);

        $local = $codes->parse("\u{00A0}05.10.10.101.аг\u{202F}");
        $this->assertNotNull($local);
        $this->assertSame("\u{00A0}05.10.10.101.аг\u{202F}", $local->rawCode);
        $this->assertSame('05.10.10.101.АГ', $local->normalizedCode);
        $this->assertSame(CommodityCodeKind::RosstatLocalAg, $local->codeKind);
        $this->assertNull($codes->parse('31'));
        $this->assertNull($codes->parse('31.02 footer'));
        $this->assertNull($codes->parse('05.10.10.101.АБ'));
        $this->assertNull($codes->parse('05. 10.10.101.АГ'));
        $this->assertTrue($territory->isRussianFederation(" Российская\u{00A0}Федерация "));
        $this->assertFalse($territory->isRussianFederation('Москва'));
        $this->assertTrue($territory->titleImpliesRussianFederation('по Российской Федерации'));
        $this->assertTrue($territory->titleImpliesRussianFederation('по Российской Федеpации'));
    }
}
