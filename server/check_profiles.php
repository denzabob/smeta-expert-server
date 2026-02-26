<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$profiles = App\Models\ParserSupplierCollectProfile::all();
echo "Profiles count: " . $profiles->count() . PHP_EOL;
foreach ($profiles as $p) {
    echo "ID={$p->id} supplier={$p->supplier_name} name={$p->name} source={$p->source} v{$p->version}" . PHP_EOL;
    echo "  selectors: " . ($p->selectors ? json_encode(array_keys($p->selectors)) : 'null') . PHP_EOL;
    echo "  extraction_rules: " . ($p->extraction_rules ? 'YES' : 'null') . PHP_EOL;
}
