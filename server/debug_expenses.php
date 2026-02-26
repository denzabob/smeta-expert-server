<?php
require 'bootstrap/app.php';
$app = require_once 'bootstrap/app.php';

$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$project = \App\Models\Project::findOrFail(1);

echo "Project ID: " . $project->id . "\n";
echo "Expenses count: " . $project->expenses()->count() . "\n";

$expenses = $project->expenses()->get();

foreach ($expenses as $expense) {
    echo "ID: {$expense->id}, Type: {$expense->type}, Cost: {$expense->cost}\n";
}

// Проверим через Service
$service = app(\App\Service\ReportService::class);
$report = $service->buildReport($project);

echo "\nExpenses from ReportDto:\n";
echo "Count: " . count($report->expenses) . "\n";
foreach ($report->expenses as $exp) {
    echo "Type: " . $exp->type . ", Cost: " . $exp->cost . "\n";
}

echo "\nExpenses in toArray():\n";
$arr = $report->toArray();
echo "Count: " . count($arr['expenses']) . "\n";
var_dump($arr['expenses']);
