<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Material;

$mat = Material::find(691);
echo json_encode($mat->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
