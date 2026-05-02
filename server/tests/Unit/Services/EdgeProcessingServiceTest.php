<?php

namespace Tests\Unit\Services;

use App\Services\EdgeProcessingService;
use App\Services\Smeta\SmetaCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class EdgeProcessingServiceTest extends TestCase
{
    #[DataProvider('edgeLengthProvider')]
    public function test_edge_processing_service_calculates_length_in_millimeters(
        string $scheme,
        float $expectedMm
    ): void {
        $this->assertSame($expectedMm, EdgeProcessingService::calculateLength($scheme, 500, 800));
    }

    public static function edgeLengthProvider(): array
    {
        return [
            'none' => ['none', 0.0],
            'around' => ['O', 2600.0],
            'two long sides' => ['=', 1600.0],
            'two short sides' => ['||', 1000.0],
            'l shape' => ['L', 1300.0],
            'p shape legacy service semantics' => ['П', 2100.0],
            'one long side' => ['long_one', 800.0],
            'one short side' => ['short_one', 500.0],
        ];
    }

    #[DataProvider('smetaEdgePerimeterProvider')]
    public function test_smeta_calculator_calculates_edge_perimeter_in_meters(
        string $scheme,
        int $quantity,
        float $expectedMeters
    ): void {
        $method = new ReflectionMethod(SmetaCalculator::class, 'calculateEdgePerimeter');
        $method->setAccessible(true);

        $actual = $method->invoke(new SmetaCalculator(), 500, 800, $quantity, $scheme);

        $this->assertSame($expectedMeters, round((float) $actual, 4));
    }

    public static function smetaEdgePerimeterProvider(): array
    {
        return [
            'none' => ['none', 1, 0.0],
            'around' => ['O', 1, 2.6],
            'two long sides' => ['=', 1, 1.6],
            'two short sides' => ['||', 1, 1.0],
            'l shape' => ['L', 1, 1.3],
            'p shape existing smeta semantics' => ['П', 1, 1.8],
            'one long side qty 1' => ['long_one', 1, 0.8],
            'one short side qty 1' => ['short_one', 1, 0.5],
            'one long side qty 2' => ['long_one', 2, 1.6],
            'one short side qty 2' => ['short_one', 2, 1.0],
        ];
    }
}
