<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

// Get API token
$token = \App\Models\User::first()?->createToken('test')->plainTextToken;
echo $token ?? 'No token';
