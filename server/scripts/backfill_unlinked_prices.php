<?php
/**
 * Backfill unlinked operation prices from resolution_queue.
 * 
 * This script reads the resolution_queue from the latest import session
 * and inserts any rows that are missing from operation_prices 
 * (i.e., rows that were skipped because no base operation was matched).
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\PriceImportSession;
use App\Models\OperationPrice;

$session = PriceImportSession::latest()->first();

if (!$session) {
    echo "No import sessions found\n";
    exit(1);
}

echo "Session: {$session->id}\n";
echo "Version: {$session->price_list_version_id}\n";
echo "Status: {$session->status}\n";

$versionId = $session->price_list_version_id;
$supplierId = $session->supplier_id;
$currency = $session->settings['currency'] ?? 'RUB';

$resolutionQueue = $session->resolution_queue ?? [];
echo "Resolution queue items: " . count($resolutionQueue) . "\n";

$existingCount = OperationPrice::where('price_list_version_id', $versionId)->count();
echo "Existing operation_prices: {$existingCount}\n\n";

$inserted = 0;
$skipped = 0;

foreach ($resolutionQueue as $item) {
    $rawData = $item['raw_data'] ?? [];
    $name = $rawData['name'] ?? '';
    
    if (empty($name)) {
        $skipped++;
        continue;
    }

    // Check if already exists (by source_name + version)
    $exists = OperationPrice::where('price_list_version_id', $versionId)
        ->where('source_name', $name)
        ->exists();
    
    if ($exists) {
        $skipped++;
        continue;
    }

    // Also check if exists by operation_id (already linked)
    $operationId = $item['operation_id'] ?? $item['matched_item_id'] ?? null;
    if ($operationId) {
        $existsById = OperationPrice::where('price_list_version_id', $versionId)
            ->where('operation_id', $operationId)
            ->exists();
        if ($existsById) {
            $skipped++;
            continue;
        }
    }

    $priceValue = floatval($rawData['price'] ?? $rawData['cost_per_unit'] ?? 0);
    $unit = $rawData['unit'] ?? null;
    $externalKey = $rawData['sku'] ?? $rawData['article'] ?? md5($name);
    $matchConfidence = $item['match_confidence'] ?? ($operationId ? 'auto' : null);

    OperationPrice::create([
        'operation_id' => $operationId,
        'supplier_id' => $supplierId,
        'price_list_version_id' => $versionId,
        'price_type' => OperationPrice::PRICE_TYPE_RETAIL,
        'price_per_internal_unit' => $priceValue,
        'source_price' => $priceValue,
        'source_unit' => $unit,
        'conversion_factor' => 1,
        'currency' => $currency,
        'source_name' => $name,
        'external_key' => $externalKey,
        'match_confidence' => $matchConfidence,
        'category' => $rawData['category'] ?? null,
        'meta' => [
            'source_row_index' => $item['row_index'] ?? null,
            'backfilled' => true,
        ],
    ]);

    $inserted++;
    echo "  + {$name} (price={$priceValue}, op_id=" . ($operationId ?? 'null') . ")\n";
}

echo "\nDone. Inserted: {$inserted}, Skipped (already exist): {$skipped}\n";
echo "Total operation_prices now: " . OperationPrice::where('price_list_version_id', $versionId)->count() . "\n";
