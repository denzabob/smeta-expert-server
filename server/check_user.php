<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';

$user = \App\Models\User::first();
if ($user) {
    echo "User found: " . $user->email . " (ID: " . $user->id . ")\n";
} else {
    echo "No users found\n";
}
