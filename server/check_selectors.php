<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$profiles = App\Models\ParserSupplierCollectProfile::whereNotNull('selectors')->get();
foreach ($profiles as $p) {
    echo "=== {$p->supplier_name} (id={$p->id}) ===" . PHP_EOL;
    echo json_encode($p->selectors, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    echo "url_patterns: " . json_encode($p->url_patterns, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    echo PHP_EOL;
}
