<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$s = \App\Models\PriceImportSession::latest()->first();
echo "session_id={$s->id} status={$s->status} version_id={$s->price_list_version_id}\n";

$rq = $s->resolution_queue ?? [];
echo "resolution_queue count=" . count($rq) . "\n";
echo "existing operation_prices=" . \App\Models\OperationPrice::where('price_list_version_id', $s->price_list_version_id)->count() . "\n";

// Show first few unmatched items
$unmatched = 0;
foreach ($rq as $item) {
    $operationId = $item['operation_id'] ?? null;
    if (!$operationId) {
        $unmatched++;
        if ($unmatched <= 5) {
            echo "  unmatched: " . ($item['raw_data']['name'] ?? 'N/A') . "\n";
        }
    }
}
echo "total unmatched in queue: {$unmatched}\n";
