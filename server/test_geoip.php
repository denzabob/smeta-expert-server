<?php

use App\Services\GeoIpService;

require_once __DIR__ . '/vendor/autoload.php';

// Test Russian IPs
$testIps = [
    '77.88.55.55'     => true,   // Yandex (Russia)
    '195.34.89.100'   => true,   // Rostelecom (Russia)
    '185.100.100.100' => true,   // Generic Russian range
    '8.8.8.8'         => false,  // Google DNS (USA)
    '1.1.1.1'         => false,  // Cloudflare (USA)
    '127.0.0.1'       => true,   // Localhost (private)
];

echo "IP Detection Test:\n";
echo str_repeat('=', 60) . "\n";

foreach ($testIps as $ip => $expectedResult) {
    $result = GeoIpService::isRussiaIp($ip);
    $status = ($result === $expectedResult) ? '✓ OK' : '✗ FAIL';
    $resultStr = $result ? 'Russia' : 'Non-Russia';
    echo sprintf("%-20s => %-15s %s\n", $ip, $resultStr, $status);
}

echo str_repeat('=', 60) . "\n";
echo "Test complete.\n";
