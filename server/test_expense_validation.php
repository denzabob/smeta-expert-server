<?php

// Test if validation rules in ProjectExpenseController match the payload

use App\Models\Project;
use App\Models\User;

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/bootstrap/app.php';

$projectId = 4;
$project = Project::find($projectId);

echo "Testing expense validation rules...\n\n";

// Simulate the payload that frontend sends
$payload = [
    'name' => 'Доставка материалов',
    'amount' => 5000,
    'description' => 'Test description'
];

echo "Frontend payload:\n";
var_dump($payload);

// Check validation rules
$rules = [
    'name' => 'required|string|max:255',
    'amount' => 'required|numeric|min:0',
    'description' => 'nullable|string'
];

echo "\nExpected validation rules:\n";
var_dump($rules);

// Test creating expense directly
try {
    $expense = $project->expenses()->create([
        'name' => $payload['name'],
        'amount' => $payload['amount'],
        'description' => $payload['description'] ?? null
    ]);

    echo "\n✓ Expense created successfully in database\n";
    echo "  ID: {$expense->id}\n";
    echo "  Name: {$expense->name}\n";
    echo "  Amount: {$expense->amount}\n";
    echo "  Description: {$expense->description}\n";

    // Test updating
    $expense->update([
        'name' => 'Updated name',
        'amount' => 7500
    ]);

    echo "\n✓ Expense updated successfully\n";
    echo "  Name: {$expense->name}\n";
    echo "  Amount: {$expense->amount}\n";

    // Test deleting
    $expense->delete();
    echo "\n✓ Expense deleted successfully\n";

} catch (\Exception $e) {
    echo "\n✗ Error: " . $e->getMessage() . "\n";
}
