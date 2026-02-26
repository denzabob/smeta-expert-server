<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Project;
use App\Models\ProjectLaborWork;
use App\Models\ProjectProfileRate;
use App\Models\PositionProfile;
use App\Services\LaborWorkRateBinder;
use Illuminate\Foundation\Testing\RefreshDatabase;

class LaborWorkRateBinderTest extends TestCase
{
    use RefreshDatabase;

    private LaborWorkRateBinder $rateBinder;
    private Project $project;
    private PositionProfile $positionProfile;
    private ProjectProfileRate $rate;
    private ProjectLaborWork $work;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rateBinder = app(LaborWorkRateBinder::class);

        // Создать тестовый проект
        $this->project = Project::factory()->create([
            'region_id' => 1,
        ]);

        // Создать профиль должности
        $this->positionProfile = PositionProfile::factory()->create([
            'title' => 'Сборщик каркаса',
        ]);

        // Создать ставку
        $this->rate = ProjectProfileRate::factory()->create([
            'project_id' => $this->project->id,
            'profile_id' => $this->positionProfile->id,
            'region_id' => 1,
            'rate_fixed' => 687.50,
            'calculation_method' => 'median',
        ]);

        // Создать работу
        $this->work = ProjectLaborWork::factory()->create([
            'project_id' => $this->project->id,
            'position_profile_id' => $this->positionProfile->id,
            'hours' => 5.0,
        ]);
    }

    /**
     * Test: bindRate привязывает ставку к работе
     */
    public function test_bind_rate_assigns_rate_to_work(): void
    {
        // Act
        $this->rateBinder->bindRate($this->work);
        $this->work->refresh();

        // Assert
        $this->assertEquals($this->rate->id, $this->work->project_profile_rate_id);
        $this->assertEquals(687.50, $this->work->rate_per_hour);
        $this->assertEquals(3437.50, $this->work->cost_total);
        $this->assertNotNull($this->work->rate_snapshot);
        $this->assertFalse($this->work->hasRateError());
    }

    /**
     * Test: rate_snapshot содержит полную информацию
     */
    public function test_rate_snapshot_contains_full_information(): void
    {
        // Act
        $this->rateBinder->bindRate($this->work);
        $this->work->refresh();

        $snapshot = $this->work->rate_snapshot;

        // Assert
        $this->assertEquals(687.50, $snapshot['rate_fixed']);
        $this->assertEquals('median', $snapshot['calculation_method']);
        $this->assertArrayHasKey('fixed_at', $snapshot);
        $this->assertArrayHasKey('applied_at', $snapshot);
        $this->assertArrayHasKey('sources_snapshot', $snapshot);
        $this->assertEquals($this->rate->id, $snapshot['rate_id']);
        $this->assertEquals($this->positionProfile->id, $snapshot['position_profile_id']);
    }

    /**
     * Test: Fallback на ставку без привязки к региону
     */
    public function test_bind_rate_fallback_to_region_null(): void
    {
        // Arrange
        // Удалить ставку с регионом
        $this->rate->delete();

        // Создать ставку без привязки к региону
        $rateNoRegion = ProjectProfileRate::factory()->create([
            'project_id' => $this->project->id,
            'profile_id' => $this->positionProfile->id,
            'region_id' => null,
            'rate_fixed' => 625.00,
            'calculation_method' => 'average',
        ]);

        // Act
        $this->rateBinder->bindRate($this->work);
        $this->work->refresh();

        // Assert
        $this->assertEquals($rateNoRegion->id, $this->work->project_profile_rate_id);
        $this->assertEquals(625.00, $this->work->rate_per_hour);
        $this->assertEquals(3125.00, $this->work->cost_total);
    }

    /**
     * Test: Если ставка не найдена, устанавливается ошибка
     */
    public function test_bind_rate_sets_error_when_not_found(): void
    {
        // Arrange
        $this->rate->delete();

        // Act
        $this->rateBinder->bindRate($this->work);
        $this->work->refresh();

        // Assert
        $this->assertNull($this->work->project_profile_rate_id);
        $this->assertNull($this->work->rate_per_hour);
        $this->assertNull($this->work->cost_total);
        $this->assertTrue($this->work->hasRateError());
        $this->assertStringContainsString('not found', $this->work->getRateErrorMessage());
    }

    /**
     * Test: Ошибка содержит детали поиска
     */
    public function test_error_snapshot_contains_search_details(): void
    {
        // Arrange
        $this->rate->delete();

        // Act
        $this->rateBinder->bindRate($this->work);
        $this->work->refresh();

        $snapshot = $this->work->rate_snapshot;

        // Assert
        $this->assertArrayHasKey('error', $snapshot);
        $this->assertArrayHasKey('error_message', $snapshot);
        $this->assertArrayHasKey('details', $snapshot);
        $this->assertEquals($this->project->id, $snapshot['details']['project_id']);
        $this->assertEquals($this->positionProfile->id, $snapshot['details']['position_profile_id']);
    }

    /**
     * Test: bindRatesForProject привязывает ставки для всех работ проекта
     */
    public function test_bind_rates_for_project_binds_all_works(): void
    {
        // Arrange
        $work2 = ProjectLaborWork::factory()->create([
            'project_id' => $this->project->id,
            'position_profile_id' => $this->positionProfile->id,
            'hours' => 3.0,
        ]);

        $work3 = ProjectLaborWork::factory()->create([
            'project_id' => $this->project->id,
            'position_profile_id' => $this->positionProfile->id,
            'hours' => 2.0,
        ]);

        // Act
        $result = $this->rateBinder->bindRatesForProject($this->project->id);

        // Assert
        $this->assertEquals(3, $result['total']);
        $this->assertEquals(3, $result['bound']);
        $this->assertEquals(0, $result['failed']);

        $this->work->refresh();
        $work2->refresh();
        $work3->refresh();

        $this->assertEquals(687.50, $this->work->rate_per_hour);
        $this->assertEquals(687.50, $work2->rate_per_hour);
        $this->assertEquals(687.50, $work3->rate_per_hour);
    }

    /**
     * Test: bindRatesForProject возвращает правильное количество ошибок
     */
    public function test_bind_rates_for_project_counts_failures(): void
    {
        // Arrange
        // Создать работу с другим профилем, для которого нет ставки
        $otherProfile = PositionProfile::factory()->create();
        $workWithoutRate = ProjectLaborWork::factory()->create([
            'project_id' => $this->project->id,
            'position_profile_id' => $otherProfile->id,
            'hours' => 2.0,
        ]);

        // Act
        $result = $this->rateBinder->bindRatesForProject($this->project->id);

        // Assert
        $this->assertEquals(2, $result['total']);
        $this->assertEquals(1, $result['bound']);
        $this->assertEquals(1, $result['failed']);

        $workWithoutRate->refresh();
        $this->assertTrue($workWithoutRate->hasRateError());
    }

    /**
     * Test: rebindWorksForRate пересчитывает ставки при их изменении
     */
    public function test_rebind_works_for_rate_recalculates_costs(): void
    {
        // Arrange
        $this->rateBinder->bindRate($this->work);
        $this->work->refresh();
        $originalCost = $this->work->cost_total; // 3437.50

        // Изменить ставку
        $this->rate->update(['rate_fixed' => 750.00]);

        // Act
        $this->rateBinder->rebindWorksForRate($this->rate);
        $this->work->refresh();

        // Assert
        $this->assertEquals(750.00, $this->work->rate_per_hour);
        $this->assertEquals(3750.00, $this->work->cost_total);
        $this->assertNotEquals($originalCost, $this->work->cost_total);
    }

    /**
     * Test: Стоимость вычисляется из hours × rate_per_hour с правильным округлением
     */
    public function test_cost_calculation_with_proper_rounding(): void
    {
        // Arrange
        $work = ProjectLaborWork::factory()->create([
            'project_id' => $this->project->id,
            'position_profile_id' => $this->positionProfile->id,
            'hours' => 2.5,
        ]);

        $rate = ProjectProfileRate::factory()->create([
            'project_id' => $this->project->id,
            'profile_id' => $this->positionProfile->id,
            'region_id' => null,
            'rate_fixed' => 333.33,
        ]);

        // Act
        $this->rateBinder->bindRate($work);
        $work->refresh();

        // Assert
        // 2.5 × 333.33 = 833.325, округляется до 833.33
        $this->assertEquals(833.33, $work->cost_total);
    }

    /**
     * Test: Model::cost attribute возвращает правильное значение
     */
    public function test_cost_attribute_priority(): void
    {
        // Arrange
        // Сценарий 1: cost_total установлен
        $this->work->update(['cost_total' => 5000.00]);
        $this->assertEquals(5000.00, $this->work->cost);

        // Сценарий 2: нет cost_total, но есть rate_per_hour
        $this->work->update(['cost_total' => null, 'rate_per_hour' => 400.00]);
        $this->assertEquals(2000.00, $this->work->cost); // 5 часов × 400

        // Сценарий 3: нет обоих, используется legacy ставка
        $this->work->update(['rate_per_hour' => null]);
        $this->project->update(['normohour_rate' => 350.00]);
        $this->assertEquals(1750.00, $this->work->cost); // 5 часов × 350

        // Сценарий 4: ничего не установлено
        $this->project->update(['normohour_rate' => null]);
        $this->assertNull($this->work->cost);
    }

    /**
     * Test: API endpoint /bind-rate привязывает ставку к работе
     */
    public function test_api_bind_rate_endpoint(): void
    {
        // Arrange
        $user = User::factory()->create();

        // Act
        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/project-labor-works/{$this->work->id}/bind-rate");

        // Assert
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.rate_per_hour', 687.50);
        $response->assertJsonPath('data.cost_total', 3437.50);
        $response->assertJsonPath('data.has_error', false);
    }

    /**
     * Test: API endpoint /rate-info возвращает информацию о ставке
     */
    public function test_api_rate_info_endpoint(): void
    {
        // Arrange
        $user = User::factory()->create();
        $this->rateBinder->bindRate($this->work);

        // Act
        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/project-labor-works/{$this->work->id}/rate-info");

        // Assert
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.id', $this->work->id);
        $response->assertJsonPath('data.rate_per_hour', 687.50);
        $response->assertJsonPath('data.cost_total', 3437.50);
        $response->assertJsonPath('data.has_rate', true);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'id',
                'title',
                'hours',
                'rate_per_hour',
                'cost_total',
                'has_rate',
                'has_error',
                'error_message',
                'rate_snapshot',
                'profile_rate',
            ],
        ]);
    }

    /**
     * Test: API endpoint /bind-labor-work-rates для проекта
     */
    public function test_api_bind_rates_for_project_endpoint(): void
    {
        // Arrange
        $user = User::factory()->create();
        ProjectLaborWork::factory()->create([
            'project_id' => $this->project->id,
            'position_profile_id' => $this->positionProfile->id,
            'hours' => 3.0,
        ]);

        // Act
        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/projects/{$this->project->id}/bind-labor-work-rates");

        // Assert
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.total', 2);
        $response->assertJsonPath('data.bound', 2);
        $response->assertJsonPath('data.failed', 0);
    }
}
