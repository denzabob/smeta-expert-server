<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Application\Support\DecimalMath;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DecimalMathTest extends TestCase
{
    #[DataProvider('roundingCases')]
    public function test_half_up_is_decimal_safe(string $input, int $scale, string $expected): void
    {
        $this->assertSame($expected, app(DecimalMath::class)->roundHalfUp($input, $scale));
    }

    public static function roundingCases(): array
    {
        return [
            ['1.234', 2, '1.23'], ['1.235', 2, '1.24'], ['1.236', 2, '1.24'],
            ['-1.235', 2, '-1.24'], ['999999999999999999.995', 2, '1000000000000000000.00'],
        ];
    }

    public function test_multiply_divide_and_compare_never_require_float(): void
    {
        $math = app(DecimalMath::class);
        $this->assertSame('0.99990000000000000000', $math->divide('99.99', '100'));
        $this->assertSame('0.99750000000000000000', $math->multiply('1.05000000000000000000', '0.95000000000000000000'));
        $this->assertSame(1, $math->compare('0.01', '0'));
    }
}
