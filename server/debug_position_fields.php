<?php

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

use App\Models\ProjectPosition;

// Get first position
$position = ProjectPosition::with('detailType')->first();

if ($position) {
    echo "=== ProjectPosition Fields ===\n";
    echo json_encode($position->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    echo "\n=== DetailType ===\n";
    if ($position->detailType) {
        echo json_encode($position->detailType->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo "No detailType\n";
    }
} else {
    echo "No positions found\n";
}
