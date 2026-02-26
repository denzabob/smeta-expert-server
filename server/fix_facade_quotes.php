<?php
/**
 * Fix facade positions that have price_method='mean' but 0 quotes.
 * For each, auto-discover MaterialPrice entries and perform aggregation.
 */
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ProjectPosition;
use App\Models\MaterialPrice;
use App\Models\ProjectPositionPriceQuote;
use App\Models\PriceListVersion;
use App\Services\PriceAggregationService;

$positions = ProjectPosition::where('kind', 'facade')
    ->where('price_method', '!=', 'single')
    ->get();

$service = app(PriceAggregationService::class);
$fixed = 0;

foreach ($positions as $position) {
    $quotesCount = $position->priceQuotes()->count();
    
    if ($quotesCount > 0) {
        echo "ID {$position->id}: already has {$quotesCount} quotes, skipping" . PHP_EOL;
        continue;
    }

    $facadeMaterialId = $position->facade_material_id;
    if (!$facadeMaterialId) {
        echo "ID {$position->id}: no facade_material_id, resetting to single" . PHP_EOL;
        $position->update(['price_method' => 'single']);
        continue;
    }

    $materialPrices = MaterialPrice::where('material_id', $facadeMaterialId)
        ->whereHas('priceListVersion', fn($q) => $q->where('status', PriceListVersion::STATUS_ACTIVE))
        ->with('priceListVersion')
        ->get();

    if ($materialPrices->count() < 2) {
        echo "ID {$position->id}: only {$materialPrices->count()} active price(s), resetting to single" . PHP_EOL;
        $position->update(['price_method' => 'single']);
        continue;
    }

    $priceValues = $materialPrices->pluck('price_per_internal_unit')
        ->map(fn($v) => (float) $v)
        ->toArray();

    $method = $position->price_method;
    $result = $service->aggregate($priceValues, $method);

    // Create quote snapshots
    $now = now();
    foreach ($materialPrices as $mp) {
        ProjectPositionPriceQuote::create([
            'project_position_id' => $position->id,
            'material_price_id' => $mp->id,
            'price_list_version_id' => $mp->price_list_version_id,
            'supplier_id' => $mp->supplier_id,
            'price_per_m2_snapshot' => (float) $mp->price_per_internal_unit,
            'captured_at' => $now,
            'mismatch_flags' => null,
        ]);
    }

    // Update position
    $position->update([
        'price_per_m2' => $result['aggregated'],
        'price_method' => $method,
        'price_sources_count' => $result['count'],
        'price_min' => $result['min'],
        'price_max' => $result['max'],
        'material_price_id' => null,
    ]);

    $position->recalculate();
    $position->save();

    echo "ID {$position->id}: FIXED — price {$position->price_per_m2} ({$method}, n={$result['count']}, range {$result['min']}-{$result['max']})" . PHP_EOL;
    $fixed++;
}

echo PHP_EOL . "Fixed {$fixed} positions" . PHP_EOL;
