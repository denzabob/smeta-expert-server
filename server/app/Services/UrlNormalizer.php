<?php

namespace App\Services;

class UrlNormalizer
{
    private const TRACKING_PARAMS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
        'yclid', 'gclid', 'fbclid', 'etext', 'ybaip', 'pm_source', 'callibri', '_openstat',
    ];

    public function normalize(?string $url): ?string
    {
        if (!$url) {
            return null;
        }

        $parts = parse_url($url);
        if (!$parts || empty($parts['host'])) {
            return mb_substr($url, 0, 2048);
        }

        $scheme = $parts['scheme'] ?? 'https';
        $host = strtolower((string) $parts['host']);
        $path = $parts['path'] ?? '/';
        $queryString = '';

        if (!empty($parts['query'])) {
            parse_str($parts['query'], $params);
            foreach (array_keys($params) as $key) {
                $lk = strtolower($key);
                if (str_starts_with($lk, 'utm_') || in_array($lk, self::TRACKING_PARAMS, true)) {
                    unset($params[$key]);
                }
            }
            if (!empty($params)) {
                ksort($params);
                $queryString = http_build_query($params);
            }
        }

        $normalized = "{$scheme}://{$host}{$path}";
        if (!empty($parts['port'])) {
            $normalized = "{$scheme}://{$host}:{$parts['port']}{$path}";
        }
        if ($queryString !== '') {
            $normalized .= "?{$queryString}";
        }

        return mb_substr($normalized, 0, 2048);
    }
}
