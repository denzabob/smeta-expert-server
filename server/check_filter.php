<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Material;

// Проверим, загружается ли материал 691 по новому фильтру
auth()->loginUsingId(1);
$user = auth()->user();

$query = Material::where(function ($q) use ($user) {
    $q->where('origin', 'parser')
      ->orWhere('user_id', $user->id);
});

$count = $query->count();
$has691 = Material::where('id', 691)
    ->where(function ($q) use ($user) {
        $q->where('origin', 'parser')
          ->orWhere('user_id', $user->id);
    })
    ->exists();

echo "Total materials with new filter: {$count}\n";
echo "Has 691 with new filter: " . ($has691 ? 'YES ✓' : 'NO ✗') . "\n\n";

$m691 = Material::find(691);
if ($m691) {
    echo "Material 691 found:\n";
    echo "  Name: " . $m691->name . "\n";
    echo "  Price: " . $m691->price_per_unit . " (type: " . gettype($m691->price_per_unit) . ")\n";
    echo "  Origin: " . $m691->origin . "\n";
    echo "  User ID: " . $m691->user_id . "\n";
    echo "  Type: " . $m691->type . "\n";
    echo "  Length MM: " . $m691->length_mm . "\n";
    echo "  Width MM: " . $m691->width_mm . "\n";
}
?>
