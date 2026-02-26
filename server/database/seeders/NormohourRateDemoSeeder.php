<?php

namespace Database\Seeders;

use App\Models\PositionProfile;
use App\Models\Region;
use App\Models\GlobalNormohourSource;
use App\Models\Project;
use App\Models\ProjectProfileRate;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Демонстрационный сидер для создания тестовых данных
 * Позволяет тестировать NormohourRateService
 */
class NormohourRateDemoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Получить или создать региона
        $sverdlovsk = Region::firstOrCreate(
            ['code' => 'SVR'],
            ['name' => 'Свердловская область']
        );

        $moscow = Region::firstOrCreate(
            ['code' => 'MSK'],
            ['name' => 'Московская область']
        );

        // Получить или создать профили должностей
        $carpenter = PositionProfile::firstOrCreate(
            ['name' => 'Столяр'],
            ['description' => 'Специалист по работе с деревом и мебелью', 'sort_order' => 1]
        );

        $assembler = PositionProfile::firstOrCreate(
            ['name' => 'Сборщик мебели'],
            ['description' => 'Специалист по сборке и монтажу мебели', 'sort_order' => 2]
        );

        $painter = PositionProfile::firstOrCreate(
            ['name' => 'Маляр'],
            ['description' => 'Специалист по окраске и отделке', 'sort_order' => 3]
        );

        // Создать источники для "Столяра" в Свердловской области
        GlobalNormohourSource::firstOrCreate(
            [
                'position_profile_id' => $carpenter->id,
                'region_id' => $sverdlovsk->id,
                'source' => 'HH.ru',
                'source_date' => now()->subDays(5),
            ],
            [
                'salary_value' => 120000,
                'salary_period' => 'month',
                'salary_month' => 120000,
                'hours_per_month' => 160,
                'rate_per_hour' => 750,
                'link' => 'https://ekaterinburg.hh.ru/vacancy/123456',
                'note' => 'Средняя зарплата по должности',
                'is_active' => true,
                'sort_order' => 1,
            ]
        );

        GlobalNormohourSource::firstOrCreate(
            [
                'position_profile_id' => $carpenter->id,
                'region_id' => $sverdlovsk->id,
                'source' => 'Avito',
                'source_date' => now()->subDays(3),
            ],
            [
                'salary_value' => 140000,
                'salary_period' => 'month',
                'salary_month' => 140000,
                'hours_per_month' => 160,
                'rate_per_hour' => 875,
                'link' => 'https://avito.ru/jobs/carpenter',
                'note' => 'Верхний диапазон зарплат',
                'is_active' => true,
                'sort_order' => 2,
            ]
        );

        GlobalNormohourSource::firstOrCreate(
            [
                'position_profile_id' => $carpenter->id,
                'region_id' => $sverdlovsk->id,
                'source' => 'Опрос подрядчиков',
                'source_date' => now()->subDays(1),
            ],
            [
                'salary_value' => 160000,
                'salary_period' => 'month',
                'salary_month' => 160000,
                'hours_per_month' => 160,
                'rate_per_hour' => 1000,
                'link' => null,
                'note' => 'Опрос рынка подрядчиков на 2026 год',
                'is_active' => true,
                'sort_order' => 3,
            ]
        );

        // Создать источники для "Сборщика мебели" (низкая волатильность)
        GlobalNormohourSource::firstOrCreate(
            [
                'position_profile_id' => $assembler->id,
                'region_id' => null, // Общий источник
                'source' => 'Сборщик мебели - среднее по России',
                'source_date' => now()->subDays(7),
            ],
            [
                'salary_value' => 95000,
                'salary_period' => 'month',
                'salary_month' => 95000,
                'hours_per_month' => 160,
                'rate_per_hour' => 594,
                'link' => null,
                'note' => 'Статистика по России',
                'is_active' => true,
                'sort_order' => 1,
            ]
        );

        GlobalNormohourSource::firstOrCreate(
            [
                'position_profile_id' => $assembler->id,
                'region_id' => null,
                'source' => 'Сборщик - нижний уровень',
                'source_date' => now()->subDays(5),
            ],
            [
                'salary_value' => 100000,
                'salary_period' => 'month',
                'salary_month' => 100000,
                'hours_per_month' => 160,
                'rate_per_hour' => 625,
                'link' => null,
                'note' => 'Минимальный уровень',
                'is_active' => true,
                'sort_order' => 2,
            ]
        );

        // Создать источники для "Маляра" (высокая волатильность)
        GlobalNormohourSource::firstOrCreate(
            [
                'position_profile_id' => $painter->id,
                'region_id' => $moscow->id,
                'source' => 'HH Москва',
                'source_date' => now()->subDays(2),
            ],
            [
                'salary_value' => 80000,
                'salary_period' => 'month',
                'salary_month' => 80000,
                'hours_per_month' => 160,
                'rate_per_hour' => 500,
                'link' => 'https://hh.ru/vacancy/moscow-painter',
                'note' => 'Нижний сегмент рынка',
                'is_active' => true,
                'sort_order' => 1,
            ]
        );

        GlobalNormohourSource::firstOrCreate(
            [
                'position_profile_id' => $painter->id,
                'region_id' => $moscow->id,
                'source' => 'Avito Москва',
                'source_date' => now()->subDays(1),
            ],
            [
                'salary_value' => 200000,
                'salary_period' => 'month',
                'salary_month' => 200000,
                'hours_per_month' => 160,
                'rate_per_hour' => 1250,
                'link' => 'https://avito.ru/moscow/painter-premium',
                'note' => 'Премиум маляры с опытом',
                'is_active' => true,
                'sort_order' => 2,
            ]
        );

        // Получить или создать тестового пользователя
        $user = User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Тестовый пользователь',
                'password' => bcrypt('password'),
            ]
        );

        // Создать тестовый проект
        $project = Project::firstOrCreate(
            ['user_id' => $user->id, 'number' => 'TEST-001'],
            [
                'expert_name' => 'Иван Иванов',
                'address' => 'ул. Главная, д. 1',
                'region_id' => $sverdlovsk->id,
                'waste_coefficient' => 1.05,
                'repair_coefficient' => 1.0,
            ]
        );

        echo "✓ Demo data created successfully!\n";
        echo "✓ Regions: {$sverdlovsk->name}, {$moscow->name}\n";
        echo "✓ Profiles: {$carpenter->name}, {$assembler->name}, {$painter->name}\n";
        echo "✓ Sources created for all profiles\n";
        echo "✓ Test project: {$project->number} (Region: {$project->region->name})\n\n";
        echo "Test the service with:\n";
        echo "1. calculateForProfile($project->id, {$carpenter->id}, {$sverdlovsk->id}, 'median')\n";
        echo "2. calculateForProfile($project->id, {$assembler->id}, null, 'average')\n";
        echo "3. upsertProjectProfileRate($project->id, {$painter->id}, {$moscow->id})\n";
    }
}
