<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Material;

auth()->loginUsingId(1);

$mat = Material::find(691);
echo "JSON serialization:\n";
echo json_encode($mat->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
echo "\n\nFields to check:\n";
echo "- length_mm: " . ($mat->toArray()['length_mm'] ?? 'MISSING') . "\n";
echo "- width_mm: " . ($mat->toArray()['width_mm'] ?? 'MISSING') . "\n";
echo "- thickness_mm: " . ($mat->toArray()['thickness_mm'] ?? 'MISSING') . "\n";
?>
