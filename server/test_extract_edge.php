<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

try {
    $service = app('App\Services\ChromeExtractService');
    $result = $service->createOrUpdateMaterial(
        1,
        'https://example.com/kromka/pvh-19x04',
        [
            'name' => 'Кромка ПВХ 19х0.4 мм Белый гладкий',
            'price' => '50 руб',
            'title' => 'Кромка ПВХ 19х0.4 мм Белый гладкий',
            'article' => 'KR-001',
        ],
        null,
        null,
        ['name' => 'manual']
    );
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (\Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . PHP_EOL;
    echo 'File: ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
}
