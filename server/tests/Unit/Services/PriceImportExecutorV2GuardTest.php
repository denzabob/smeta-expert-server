<?php

namespace Tests\Unit\Services;

use App\Models\Operation;
use App\Services\PriceImport\PriceImportExecutorV2;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use ReflectionClass;
use Tests\TestCase;

class PriceImportExecutorV2GuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_link_guard_rejects_obvious_mismatch(): void
    {
        $operation = Operation::create([
            'name' => 'Кромкооблицовка прямолинейная ПВХ 1,0х19',
            'category' => 'Работы',
            'unit' => 'м.п.',
        ]);

        $executor = new PriceImportExecutorV2();
        $method = (new ReflectionClass($executor))->getMethod('assertManualLinkConsistency');
        $method->setAccessible(true);

        $this->expectException(InvalidArgumentException::class);
        $method->invoke(
            $executor,
            'Кромкооблицовка криволинейная ПВХ 2,0х19',
            $operation->id,
            10
        );
    }

    public function test_manual_link_guard_accepts_same_semantics(): void
    {
        $operation = Operation::create([
            'name' => 'Распиловка ДСП 10-16-22-25 мм',
            'category' => 'Работы',
            'unit' => 'м²',
        ]);

        $executor = new PriceImportExecutorV2();
        $method = (new ReflectionClass($executor))->getMethod('assertManualLinkConsistency');
        $method->setAccessible(true);

        $method->invoke(
            $executor,
            'Распиловка ДСП 10-16-22-25 мм',
            $operation->id,
            12
        );

        $this->assertTrue(true);
    }
}

