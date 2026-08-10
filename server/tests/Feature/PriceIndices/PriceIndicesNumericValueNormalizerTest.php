<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Domain\Enums\StatisticalObservationMissingReason;
use App\Domain\PriceIndices\Domain\Exceptions\StatisticalImportParsingFailed;
use App\Domain\PriceIndices\Infrastructure\Import\StatisticalNumericValueNormalizer;
use Tests\TestCase;

class PriceIndicesNumericValueNormalizerTest extends TestCase
{
    public function test_excel_tail_is_deterministically_rounded_by_display_precision(): void
    {
        $value = app(StatisticalNumericValueNormalizer::class)->normalize('75.849999999999994', 'n', '0.00');
        $this->assertSame('75.85', $value->value);
        $this->assertSame('75.849999999999994', $value->raw);
    }

    public function test_known_footnote_class_is_parsed_without_stripping_arbitrary_text(): void
    {
        $value = app(StatisticalNumericValueNormalizer::class)->normalize('97,511)', 's', '0.00');
        $this->assertSame('97.51', $value->value);
        $this->assertSame('1)', $value->footnoteMarker);
        $this->assertTrue($value->specialFootnoted);
    }

    public function test_all_missing_markers_map_without_becoming_zero(): void
    {
        $normalizer = app(StatisticalNumericValueNormalizer::class);
        $this->assertSame(StatisticalObservationMissingReason::Blank, $normalizer->normalize('', 's')->missingReason);
        $this->assertSame(StatisticalObservationMissingReason::Ellipsis, $normalizer->normalize('…', 's')->missingReason);
        $this->assertSame(StatisticalObservationMissingReason::ThreeDots, $normalizer->normalize('...', 's')->missingReason);
        $this->assertSame(StatisticalObservationMissingReason::Dash, $normalizer->normalize('-', 's')->missingReason);
    }

    public function test_formula_and_unknown_rich_text_are_fatal(): void
    {
        $normalizer = app(StatisticalNumericValueNormalizer::class);
        try {
            $normalizer->normalize('=1+1', 'f');
            $this->fail('Formula unexpectedly accepted.');
        } catch (StatisticalImportParsingFailed $exception) {
            $this->assertSame('formula_in_supported_cell', $exception->failureCode);
        }

        $this->expectException(StatisticalImportParsingFailed::class);
        $normalizer->normalize('97,51 прим.', 's');
    }
}
