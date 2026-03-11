<?php
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';

use App\Models\Material;

$materials = Material::where('name', 'like', '%ЛДСП Выбеленное дерево 260 Л%')
    ->orderBy('id', 'desc')
    ->get(['id', 'name', 'type', 'price_per_unit', 'length_mm', 'width_mm', 'user_id', 'origin', 'created_at']);

echo "Found materials: " . count($materials) . "\n\n";

foreach ($materials as $mat) {
    echo "ID: " . $mat->id . "\n";
    echo "Name: " . $mat->name . "\n";
    echo "Type: " . $mat->type . "\n";
    echo "Price per unit: " . var_export($mat->price_per_unit, true) . " (type: " . gettype($mat->price_per_unit) . ")\n";
    echo "Length MM: " . var_export($mat->length_mm, true) . "\n";
    echo "Width MM: " . var_export($mat->width_mm, true) . "\n";
    echo "User ID: " . var_export($mat->user_id, true) . "\n";
    echo "Origin: " . $mat->origin . "\n";
    echo "Created at: " . $mat->created_at . "\n";
    echo "---\n\n";
}
?>
