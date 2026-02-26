<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;

$user = User::where('email', 'test@example.com')->first();
if ($user) {
    $token = $user->createToken('test-token')->plainTextToken;
    echo $token . "\n";
} else {
    echo "User not found\n";
    exit(1);
}
