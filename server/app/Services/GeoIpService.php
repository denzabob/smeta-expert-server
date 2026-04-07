<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

class GeoIpService
{
    /**
     * Check if an IP address belongs to Russia
     * Returns true if IP is in Russia, false otherwise
     */
    public static function isRussiaIp(string $ip): bool
    {
        // Skip private IPs (assume they are local)
        if (self::isPrivateIp($ip)) {
            return true;
        }

        // Convert IP to long for range checking
        $ipLong = ip2long($ip);
        
        if ($ipLong === false) {
            return false;
        }

        $russiaRanges = self::getRussiaIpRanges();

        foreach ($russiaRanges as $range) {
            $startLong = ip2long($range['start']);
            $endLong = ip2long($range['end']);

            if ($startLong === false || $endLong === false) {
                continue;
            }

            if ($ipLong >= $startLong && $ipLong <= $endLong) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if IP is private/local
     */
    private static function isPrivateIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /**
     * Get Russia IP ranges (popular providers)
     * This is a simplified list of major Russian ISP ranges
     */
    private static function getRussiaIpRanges(): array
    {
        // Simplified list of Russian IP ranges (starting with major providers)
        // In production, consider using MaxMind GeoLite2 database
        return [
            // Rostelecom
            ['start' => '31.173.64.0', 'end' => '31.173.127.255'],
            ['start' => '31.44.0.0', 'end' => '31.44.255.255'],
            ['start' => '37.140.0.0', 'end' => '37.143.255.255'],
            
            // Beeline (Veon)
            ['start' => '195.34.89.0', 'end' => '195.34.89.255'],
            ['start' => '188.0.0.0', 'end' => '188.255.255.255'],
            
            // MegaFon
            ['start' => '217.0.0.0', 'end' => '217.255.255.255'],
            
            // Yandex networks
            ['start' => '77.88.0.0', 'end' => '77.88.255.255'],
            ['start' => '141.98.251.0', 'end' => '141.98.251.255'],
            
            // Livejournal, Mail.ru, etc
            ['start' => '84.201.128.0', 'end' => '84.201.191.255'],
            ['start' => '212.76.0.0', 'end' => '212.76.255.255'],
            
            // Additional IPs
            ['start' => '178.0.0.0', 'end' => '178.255.255.255'],
            ['start' => '79.0.0.0', 'end' => '79.255.255.255'],
            ['start' => '80.0.0.0', 'end' => '81.255.255.255'],
            ['start' => '82.0.0.0', 'end' => '83.255.255.255'],
            ['start' => '92.0.0.0', 'end' => '93.255.255.255'],
            ['start' => '94.140.0.0', 'end' => '94.143.255.255'],
            ['start' => '109.0.0.0', 'end' => '109.255.255.255'],
            ['start' => '185.0.0.0', 'end' => '185.255.255.255'],
            ['start' => '188.0.0.0', 'end' => '189.255.255.255'],
            ['start' => '193.0.0.0', 'end' => '193.255.255.255'],
            ['start' => '194.0.0.0', 'end' => '194.255.255.255'],
            ['start' => '195.0.0.0', 'end' => '195.255.255.255'],
            ['start' => '200.0.0.0', 'end' => '200.255.255.255'],
        ];
    }

    /**
     * Get client IP from request
     */
    public static function getClientIp(\Illuminate\Http\Request $request): ?string
    {
        // Check for shared internet
        if (!empty($request->server('HTTP_CLIENT_IP'))) {
            return $request->server('HTTP_CLIENT_IP');
        }
        
        // Check for IP passed from proxy
        if (!empty($request->server('HTTP_X_FORWARDED_FOR'))) {
            $ips = explode(',', $request->server('HTTP_X_FORWARDED_FOR'));
            return trim($ips[0]);
        }
        
        // Direct connection
        return $request->ip();
    }
}
