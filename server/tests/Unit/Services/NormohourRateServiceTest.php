<?php

namespace Tests\Unit\Services;

use App\DTOs\CalculationResultDTO;
use App\Models\GlobalNormohourSource;
use App\Models\PositionProfile;
use App\Models\Project;
use App\Models\ProjectProfileRate;
use App\Models\Region;
use App\Models\User;
use App\Services\NormohourRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit тесты для NormohourRateService
 * 
 * Проверяют:
 * - Расчет медианы и среднего
 * - Детекцию волатильности
 * - Создание/обновление ставок
 * - Фильтрацию по регионам
 * - Обработку блокированных ставок
 */
class NormohourRateServiceTest extends TestCase
{
    use RefreshDatabase;

    private NormohourRateService $service;
    private User $user;
    private Region $region;
    private PositionProfile $profile;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(NormohourRateService::class);

        // Создать тестовые данные
        $this->user = User::factory()->create();
        $this->region = Region::factory()->create(['name' => 'Тестовый регион']);
        $this->profile = PositionProfile::factory()->create(['name' => 'Тестовый профиль']);
        $this->project = Project::factory()
            ->for($this->user)
            ->create(['region_id' => $this->region->id]);
    }

    /**
     * Тест: Расчет медианы из нечетного количества значений
     */
    public function test_calculate_median_odd_count(): void
    {
        // Создать источники: 100, 200, 300
        $this->createSources([100, 200, 300]);

        $result = $this->service->calculateForProfile(
            $this->project->id,
            $this->profile->id,
            $this->region->id,
            'median'
        );

        // Медиана: 200
        $this->assertEquals(200, $result->rate);
        $this->assertEquals('median', $result->method);
        $this->assertEquals(3, $result->sourceCount);
    }

    /**
     * Тест: Расчет медианы из четного количества значений
     */
    public function test_calculate_median_even_count(): void
    {
        // Создать источники: 100, 200, 300, 400
        $this->createSources([100, 200, 300, 400]);

        $result = $this->service->calculateForProfile(
            $this->project->id,
            $this->profile->id,
            $this->region->id,
            'median'
        );

        // Медиана: (200 + 300) / 2 = 250
        $this->assertEquals(250, $result->rate);
    }

    /**
     * Тест: Расчет среднего арифметического
     */
    public function test_calculate_average(): void
    {
        // Создать источники: 100, 200, 300
        $this->createSources([100, 200, 300]);

        $result = $this->service->calculateForProfile(
            $this->project->id,
            $this->profile->id,
            $this->region->id,
            'average'
        );

        // Среднее: (100 + 200 + 300) / 3 = 200
        $this->assertEquals(200, $result->rate);
        $this->assertEquals('average', $result->method);
    }

    /**
     * Тест: Расчет волатильности (нормальная)
     */
    public function test_volatility_normal(): void
    {
        // Создать источники: 950, 1000, 1050
        // Волатильность: (1050 - 950) / 950 * 100 = 10.53%
        $this->createSources([950, 1000, 1050]);

        $result = $this->service->calculateForProfile(
            $this->project->id,
            $this->profile->id,
            $this->region->id
        );

        $this->assertLessThan(30, $result->volatility);
        $this->assertFalse($result->hasHighVolatility());
        $this->assertEmpty($result->warnings); // Нет предупреждений о волатильности
    }

    /**
     * Тест: Расчет волатильности (высокая, выше порога 30%)
     */
    public function test_volatility_high(): void
    {
        // Создать источники: 500, 1000, 1500
        // Волатильность: (1500 - 500) / 500 * 100 = 200%
        $this->createSources([500, 1000, 1500]);

        $result = $this->service->calculateForProfile(
            $this->project->id,
            $this->profile->id,
            $this->region->id
        );

        $this->assertGreaterThanOrEqual(30, $result->volatility);
        $this->assertTrue($result->hasHighVolatility());
        $this->assertTrue($result->hasWarnings());
        $this->assertStringContainsString('Высокая волатильность', $result->warnings[0]['message']);
    }

    /**
     * Тест: Предупреждение при малом количестве источников
     */
    public function test_warning_low_source_count(): void
    {
        // Создать только 1 источник
        $this->createSources([1000]);

        $result = $this->service->calculateForProfile(
            $this->project->id,
            $this->profile->id,
            $this->region->id
        );

        $this->assertTrue($result->hasWarnings());
        $this->assertStringContainsString('менее 3 источников', $result->warnings[0]['message']);
    }

    /**
     * Тест: Фильтрация по регионам (включают региональные и общие)
     */
    public function test_filter_by_region(): void
    {
        $otherRegion = Region::factory()->create();

        // Создать источники только для текущего региона
        $this->createSources([100, 200, 300], $this->region->id);

        // Создать источники для другого региона (не должны использоваться)
        $this->createSources([500, 600], $otherRegion->id);

        $result = $this->service->calculateForProfile(
            $this->project->id,
            $this->profile->id,
            $this->region->id
        );

        // Должны использоваться только источники первого региона
        $this->assertEquals(3, $result->sourceCount);
        $this->assertStringContainsString('200', $result->justificationSnapshot);
    }

    /**
     * Тест: Использование общих источников (region_id = null)
     */
    public function test_use_global_sources(): void
    {
        // Создать источники без региона (общие)
        $this->createSources([1000, 1100, 1200], null);

        $result = $this->service->calculateForProfile(
            $this->project->id,
            $this->profile->id,
            null // Без указания региона
        );

        $this->assertEquals(3, $result->sourceCount);
    }

    /**
     * Тест: Исключение неактивных источников
     */
    public function test_exclude_inactive_sources(): void
    {
        // Создать активные источники
        $this->createSources([100, 200, 300], $this->region->id);

        // Создать неактивные источники
        GlobalNormohourSource::factory()
            ->for($this->profile, 'positionProfile')
            ->for($this->region)
            ->create(['rate_per_hour' => 999, 'is_active' => false]);

        $result = $this->service->calculateForProfile(
            $this->project->id,
            $this->profile->id,
            $this->region->id
        );

        // Должны использоваться только активные
        $this->assertEquals(3, $result->sourceCount);
    }

    /**
     * Тест: Ошибка при отсутствии источников
     */
    public function test_error_no_sources(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Не найдены источники');

        $this->service->calculateForProfile(
            $this->project->id,
            $this->profile->id,
            $this->region->id
        );
    }

    /**
     * Тест: Создание новой ставки (upsert)
     */
    public function test_upsert_create_new_rate(): void
    {
        $this->createSources([100, 200, 300]);

        $rate = $this->service->upsertProjectProfileRate(
            $this->project->id,
            $this->profile->id,
            $this->region->id
        );

        $this->assertInstanceOf(ProjectProfileRate::class, $rate);
        $this->assertEquals($this->project->id, $rate->project_id);
        $this->assertEquals($this->profile->id, $rate->profile_id);
        $this->assertEquals(200, $rate->rate_fixed);
        $this->assertFalse($rate->is_locked);

        // Проверить, что запись сохранена в БД
        $this->assertDatabaseHas('project_profile_rates', [
            'project_id' => $this->project->id,
            'profile_id' => $this->profile->id,
        ]);
    }

    /**
     * Тест: Обновление существующей ставки
     */
    public function test_upsert_update_existing_rate(): void
    {
        // Создать первую версию ставки
        $this->createSources([100, 200, 300]);
        $rate1 = $this->service->upsertProjectProfileRate(
            $this->project->id,
            $this->profile->id,
            $this->region->id,
            'median'
        );

        // Обновить источники
        GlobalNormohourSource::where('position_profile_id', $this->profile->id)
            ->delete();
        $this->createSources([300, 400, 500]);

        // Выполнить upsert еще раз
        $rate2 = $this->service->upsertProjectProfileRate(
            $this->project->id,
            $this->profile->id,
            $this->region->id,
            'median'
        );

        // ID должен быть одинаковым (обновление, не создание)
        $this->assertEquals($rate1->id, $rate2->id);
        // Но значение должно измениться (было 200, стало 400)
        $this->assertEquals(400, $rate2->rate_fixed);
    }

    /**
     * Тест: Не обновлять заблокированную ставку
     */
    public function test_respect_locked_rate(): void
    {
        $this->createSources([100, 200, 300]);

        // Создать ставку
        $rate = $this->service->upsertProjectProfileRate(
            $this->project->id,
            $this->profile->id,
            $this->region->id
        );

        // Заблокировать ставку
        $rate->update([
            'is_locked' => true,
            'lock_reason' => 'Согласовано с клиентом'
        ]);

        $originalRate = $rate->rate_fixed;

        // Обновить источники
        GlobalNormohourSource::where('position_profile_id', $this->profile->id)
            ->delete();
        $this->createSources([500, 600, 700]);

        // Попытаться upsert
        $updatedRate = $this->service->upsertProjectProfileRate(
            $this->project->id,
            $this->profile->id,
            $this->region->id
        );

        // Ставка не должна измениться
        $this->assertEquals($originalRate, $updatedRate->rate_fixed);
        $this->assertTrue($updatedRate->is_locked);
    }

    /**
     * Тест: DTO преобразование в массив
     */
    public function test_result_dto_to_array(): void
    {
        $this->createSources([100, 200, 300]);

        $result = $this->service->calculateForProfile(
            $this->project->id,
            $this->profile->id,
            $this->region->id
        );

        $array = $result->toArray();

        $this->assertIsArray($array);
        $this->assertArrayHasKey('rate', $array);
        $this->assertArrayHasKey('sources_snapshot', $array);
        $this->assertArrayHasKey('justification_snapshot', $array);
        $this->assertArrayHasKey('volatility', $array);
        $this->assertArrayHasKey('warnings', $array);
        $this->assertArrayHasKey('method', $array);
        $this->assertArrayHasKey('source_count', $array);
    }

    /**
     * Тест: Структура sources_snapshot
     */
    public function test_sources_snapshot_structure(): void
    {
        $this->createSources([100, 200, 300]);

        $result = $this->service->calculateForProfile(
            $this->project->id,
            $this->profile->id,
            $this->region->id
        );

        $sources = $result->sourcesSnapshot;
        $this->assertNotEmpty($sources);

        $source = $sources[0];
        $this->assertArrayHasKey('source_id', $source);
        $this->assertArrayHasKey('source', $source);
        $this->assertArrayHasKey('rate_per_hour', $source);
        $this->assertArrayHasKey('salary_period', $source);
        $this->assertArrayHasKey('salary_month', $source);
        $this->assertArrayHasKey('hours_per_month', $source);
        $this->assertArrayHasKey('source_date', $source);
        $this->assertArrayHasKey('region_id', $source);
        $this->assertArrayHasKey('link', $source);
    }

    // ============ Helper Methods ============

    /**
     * Создать источники с заданными ставками
     */
    private function createSources(array $rates, ?int $regionId = null): void
    {
        foreach ($rates as $index => $rate) {
            GlobalNormohourSource::factory()
                ->for($this->profile, 'positionProfile')
                ->when($regionId !== null, fn($query) => $query->for(Region::find($regionId)))
                ->create([
                    'rate_per_hour' => $rate,
                    'salary_month' => $rate * 160,
                    'source' => "Источник #{$index + 1}",
                    'source_date' => now()->subDays($index),
                    'is_active' => true,
                    'sort_order' => $index,
                ]);
        }
    }
}
