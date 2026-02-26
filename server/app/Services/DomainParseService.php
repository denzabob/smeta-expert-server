<?php

namespace App\Services;

use App\Models\ParserSupplierCollectProfile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Service for parsing material data from URLs using domain-specific CSS selectors.
 * 
 * Uses ParserSupplierCollectProfile to find stored selectors for a domain,
 * then uses Playwright + selectors to extract data from the page.
 */
class DomainParseService
{
    /**
     * Check if we have parsing selectors for a given domain.
     *
     * @param string $url Full URL or domain
     * @param int|null $userId User ID for user-specific profiles
     * @return array{supported: bool, profile: ?ParserSupplierCollectProfile, selectors: ?array, source: ?string}
     */
    public function checkDomainSupport(string $url, ?int $userId = null): array
    {
        $domain = $this->extractDomain($url);
        if (!$domain) {
            return ['supported' => false, 'profile' => null, 'selectors' => null, 'source' => null];
        }

        // Search for profile: user-specific first, then system
        $profile = $this->findProfile($domain, $userId);

        if (!$profile || empty($profile->selectors)) {
            // Also check parser JSON configs on disk
            $jsonConfig = $this->findJsonConfig($domain);
            if ($jsonConfig && !empty($jsonConfig['selectors'])) {
                return [
                    'supported' => true,
                    'profile' => null,
                    'selectors' => $jsonConfig['selectors'],
                    'source' => 'parser_config',
                    'config' => $jsonConfig,
                ];
            }

            return ['supported' => false, 'profile' => null, 'selectors' => null, 'source' => null];
        }

        return [
            'supported' => true,
            'profile' => $profile,
            'selectors' => $profile->selectors,
            'source' => $profile->source, // 'system' | 'chrome_ext'
        ];
    }

    /**
     * Parse a URL using domain-specific selectors (Playwright).
     *
     * @param string $url
     * @param array $selectors CSS selectors map {title: ..., price: ..., article: ...}
     * @return array{success: bool, data: ?array, message: string}
     */
    public function parseWithSelectors(string $url, array $selectors): array
    {
        $scriptPath = base_path('scripts/scrape-with-selectors.js');

        if (!file_exists($scriptPath)) {
            Log::error('DomainParseService: scrape-with-selectors.js not found', ['path' => $scriptPath]);
            return [
                'success' => false,
                'data' => null,
                'message' => 'Скрипт парсинга не найден',
            ];
        }

        $selectorsJson = json_encode($selectors, JSON_UNESCAPED_UNICODE);

        try {
            $result = Process::timeout(45)
                ->env([
                    'PLAYWRIGHT_BROWSERS_PATH' => '/root/.cache/ms-playwright',
                    'HOME' => '/root',
                ])
                ->run(['node', $scriptPath, $url, $selectorsJson]);

            if ($result->successful()) {
                $output = trim($result->output());
                $parsed = json_decode($output, true);

                if ($parsed && $parsed['success'] && !empty($parsed['data'])) {
                    $data = $parsed['data'];

                    // Clean and normalize extracted data
                    return [
                        'success' => true,
                        'data' => [
                            'name' => $this->cleanName($data['title'] ?? null),
                            'article' => $this->cleanArticle($data['article'] ?? null),
                            'price_per_unit' => $this->extractPrice($data['price'] ?? null),
                            'source_url' => $url,
                        ],
                        'message' => 'Данные извлечены с помощью сохранённых селекторов',
                    ];
                }
            }

            // Parse stderr for error info
            $errorOutput = $result->errorOutput();
            Log::warning('DomainParseService: Playwright parse failed', [
                'url' => $url,
                'exit_code' => $result->exitCode(),
                'stderr' => substr($errorOutput, 0, 500),
            ]);

            return [
                'success' => false,
                'data' => null,
                'message' => 'Не удалось извлечь данные со страницы',
            ];
        } catch (\Throwable $e) {
            Log::error('DomainParseService: Exception during parsing', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'data' => null,
                'message' => 'Ошибка парсинга: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Full flow: check domain → parse with selectors if available.
     *
     * @param string $url
     * @param int|null $userId
     * @return array{success: bool, data: ?array, has_selectors: bool, message: string}
     */
    public function parseUrl(string $url, ?int $userId = null): array
    {
        $domainCheck = $this->checkDomainSupport($url, $userId);

        if (!$domainCheck['supported']) {
            return [
                'success' => false,
                'data' => null,
                'has_selectors' => false,
                'message' => 'Для данного домена нет сохранённых правил парсинга',
            ];
        }

        $result = $this->parseWithSelectors($url, $domainCheck['selectors']);
        $result['has_selectors'] = true;

        return $result;
    }

    /**
     * Find a profile for a domain.
     */
    protected function findProfile(string $domain, ?int $userId = null): ?ParserSupplierCollectProfile
    {
        $baseQuery = ParserSupplierCollectProfile::forDomain($domain)
            ->whereNotNull('selectors');

        if ($userId) {
            // Priority:
            // 1) user templates
            // 2) system templates
            // Inside each group prefer default + latest version.
            return (clone $baseQuery)
                ->where(function ($q) use ($userId) {
                    $q->where('user_id', $userId)->orWhereNull('user_id');
                })
                ->orderByRaw('CASE WHEN user_id = ? THEN 0 ELSE 1 END', [$userId])
                ->orderByDesc('is_default')
                ->orderByDesc('version')
                ->orderByDesc('id')
                ->first();
        }

        // Fallback to system profile only
        return (clone $baseQuery)
            ->whereNull('user_id')
            ->orderByDesc('is_default')
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Check parser JSON configs (on disk) for a domain match.
     */
    protected function findJsonConfig(string $domain): ?array
    {
        $configDir = base_path('../parser/configs');
        if (!is_dir($configDir)) {
            return null;
        }

        foreach (glob($configDir . '/*.json') as $file) {
            $basename = pathinfo($file, PATHINFO_FILENAME);
            if ($basename === 'template') continue;

            try {
                $config = json_decode(file_get_contents($file), true);
                if (!$config) continue;

                // Match by base_url domain
                $configDomain = parse_url($config['base_url'] ?? '', PHP_URL_HOST);
                $configDomain = preg_replace('/^www\./', '', $configDomain ?? '');

                // Also check supplier_name with domain normalization
                $supplierDomain = str_replace('_', '-', $basename);

                if ($configDomain === $domain || $supplierDomain === str_replace('.', '-', $domain)
                    || str_contains($domain, $supplierDomain)) {
                    return $config;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return null;
    }

    /**
     * Extract domain from URL.
     */
    protected function extractDomain(string $url): ?string
    {
        // If it's already just a domain, use it
        if (!str_contains($url, '://')) {
            return preg_replace('/^www\./', '', strtolower($url));
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) return null;

        return preg_replace('/^www\./', '', strtolower($host));
    }

    /**
     * Clean extracted name.
     */
    protected function cleanName(?string $name): ?string
    {
        if (!$name) return null;

        // Remove commercial suffixes
        $name = preg_replace('/\s+(?:купить|заказать|в компании|в магазине|магазин|интернет[\s-]магазин|online|shop).*/ui', '', $name);
        $name = trim($name);

        return $name ?: null;
    }

    /**
     * Clean extracted article.
     */
    protected function cleanArticle(?string $article): ?string
    {
        if (!$article) return null;

        // Remove common prefixes like "Артикул:", "Код:", "SKU:"
        $article = preg_replace('/^(?:артикул|арт|код|sku|article)\s*[:：.]\s*/ui', '', $article);
        $article = trim($article);

        return $article ?: null;
    }

    /**
     * Extract numeric price from a price string.
     */
    protected function extractPrice(?string $priceStr): ?float
    {
        if (!$priceStr) return null;

        // Remove currency symbols, spaces, non-breaking spaces
        $cleaned = preg_replace('/[^\d.,]/', '', str_replace(["\xC2\xA0", "\xA0", ' '], '', $priceStr));

        // Handle comma as decimal separator: "1234,56" → "1234.56"
        if (preg_match('/^(\d+),(\d{1,2})$/', $cleaned, $m)) {
            return (float) ($m[1] . '.' . $m[2]);
        }

        // Handle dot as decimal: "1234.56"
        if (preg_match('/^[\d]+\.[\d]{1,2}$/', $cleaned)) {
            return (float) $cleaned;
        }

        // Just digits
        $cleaned = preg_replace('/[.,]/', '', $cleaned);
        if (is_numeric($cleaned)) {
            return (float) $cleaned;
        }

        return null;
    }

    /**
     * Detect material type from name and URL.
     * Reuses logic from ChromeExtractService.
     */
    public static function detectMaterialType(?string $name, ?string $url): string
    {
        $nameLower = mb_strtolower($name ?? '');
        $urlLower = strtolower($url ?? '');

        // Edge detection
        if (str_contains($nameLower, 'кромка') || str_contains($urlLower, 'kromka') || str_contains($urlLower, 'edge')) {
            return 'edge';
        }

        // Plate detection
        $platePatterns = ['лдсп', 'дсп', 'мдф', 'хдф', 'двп', 'фанера', 'osb', 'осб', 'лмдф'];
        foreach ($platePatterns as $pattern) {
            if (str_contains($nameLower, $pattern)) return 'plate';
        }

        $plateUrlPatterns = ['ldsp', 'dsp', 'mdf', 'hdf', 'dvp', 'faner', 'osb', 'lmdf'];
        foreach ($plateUrlPatterns as $pattern) {
            if (str_contains($urlLower, $pattern)) return 'plate';
        }

        // Facade detection
        if (str_contains($nameLower, 'фасад') || str_contains($urlLower, 'fasad') || str_contains($urlLower, 'facade')) {
            return 'facade';
        }

        // Default: hardware
        return 'hardware';
    }
}
