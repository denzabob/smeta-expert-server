<?php
require 'vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Project;
use App\Models\ProjectFitting;
use App\Models\Expense;
use Illuminate\Support\Facades\Hash;

try {
    // Создать тестового пользователя если его нет
    $user = User::firstOrCreate(
        ['email' => 'test@example.com'],
        [
            'name' => 'Test User',
            'password' => Hash::make('password'),
        ]
    );

    // Создать тестовый проект если его нет
    $project = Project::firstOrCreate(
        ['user_id' => $user->id, 'number' => 'TEST-001'],
        [
            'expert_name' => 'Иван Петров',
            'address' => 'ул. Тестовая, 123',
            'waste_coefficient' => 1.1,
            'repair_coefficient' => 1.05,
            'waste_plate_coefficient' => 1.08,
            'waste_edge_coefficient' => 1.05,
            'waste_operations_coefficient' => 1.02,
            'apply_waste_to_plate' => true,
            'apply_waste_to_edge' => true,
            'apply_waste_to_operations' => false,
            'use_area_calc_mode' => false,
        ]
    );

    // Создать тестовые фиттинги если их нет
    if ($project->fittings()->count() === 0) {
        ProjectFitting::create([
            'project_id' => $project->id,
            'name' => 'Петля мебельная',
            'article' => 'HNG-001',
            'unit' => 'шт',
            'quantity' => 8,
            'unit_price' => 150.50,
        ]);

        ProjectFitting::create([
            'project_id' => $project->id,
            'name' => 'Направляющая шариковая',
            'article' => 'GUID-002',
            'unit' => 'пара',
            'quantity' => 4,
            'unit_price' => 450.00,
        ]);
    }

    // Создать тестовые расходы если их нет
    if ($project->expenses()->count() === 0) {
        Expense::create([
            'project_id' => $project->id,
            'type' => 'доставка',
            'cost' => 2500.00,
            'description' => 'Доставка материалов',
        ]);

        Expense::create([
            'project_id' => $project->id,
            'type' => 'работы',
            'cost' => 15000.00,
            'description' => 'Установка и сборка',
        ]);
    }

    echo "✅ Тестовые данные созданы:\n";
    echo "   - Пользователь: {$user->email}\n";
    echo "   - Проект: {$project->number} (ID: {$project->id})\n";
    echo "   - Фиттинги: {$project->fittings()->count()} шт.\n";
    echo "   - Расходы: {$project->expenses()->count()} шт.\n";

} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    exit(1);
}
