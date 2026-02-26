<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ProjectPosition;
use App\Models\MaterialPrice;
use App\Models\PriceListVersion;

// 1. Show facade positions with non-single method
$positions = ProjectPosition::where('kind', 'facade')
    ->where('price_method', '!=', 'single')
    ->get();

echo "=== Facade positions with non-single method ===" . PHP_EOL;
foreach ($positions as $p) {
    $qc = $p->priceQuotes()->count();
    echo "ID {$p->id}: method={$p->price_method}, price={$p->price_per_m2}, sources={$p->price_sources_count}, quotes={$qc}" . PHP_EOL;
}
echo "Total: " . count($positions) . PHP_EOL;

// 2. Show available MaterialPrices for material 687
echo PHP_EOL . "=== MaterialPrices for material 687 ===" . PHP_EOL;
$prices = MaterialPrice::where('material_id', 687)
    ->with(['priceListVersion', 'supplier'])
    ->get();
foreach ($prices as $mp) {
    $status = $mp->priceListVersion->status ?? 'unknown';
    $supplierName = $mp->supplier->name ?? 'unknown';
    echo "  MP ID {$mp->id}: price={$mp->price_per_internal_unit}, supplier={$supplierName}, version_status={$status}" . PHP_EOL;
}

// 3. Show active-only
echo PHP_EOL . "=== Active MaterialPrices for material 687 ===" . PHP_EOL;
$activeIds = MaterialPrice::where('material_id', 687)
    ->whereHas('priceListVersion', fn($q) => $q->where('status', PriceListVersion::STATUS_ACTIVE))
    ->pluck('id')
    ->toArray();
echo "Active price IDs: " . implode(', ', $activeIds) . PHP_EOL;
echo "Count: " . count($activeIds) . PHP_EOL;
