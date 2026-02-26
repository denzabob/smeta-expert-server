<?php
// Скрипт для создания тестовых данных
use App\Models\User;
use App\Models\Project;
use App\Models\ProjectFitting;
use App\Models\Expense;
use Illuminate\Support\Facades\Hash;

$user = User::firstOrCreate(
    ['email' => 'test@example.com'],
    ['name' => 'Test User', 'password' => Hash::make('password')]
);

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

ProjectFitting::firstOrCreate(['project_id' => $project->id, 'article' => 'HNG-001'], [
    'name' => 'Петля мебельная',
    'unit' => 'шт',
    'quantity' => 8,
    'unit_price' => 150.50,
]);

ProjectFitting::firstOrCreate(['project_id' => $project->id, 'article' => 'GUID-002'], [
    'name' => 'Направляющая шариковая',
    'unit' => 'пара',
    'quantity' => 4,
    'unit_price' => 450.00,
]);

Expense::firstOrCreate(['project_id' => $project->id, 'type' => 'доставка'], [
    'cost' => 2500.00,
    'description' => 'Доставка материалов',
]);

Expense::firstOrCreate(['project_id' => $project->id, 'type' => 'работы'], [
    'cost' => 15000.00,
    'description' => 'Установка и сборка',
]);

echo "✅ Данные созданы: Project #{$project->id}, User #{$user->id}\n";
