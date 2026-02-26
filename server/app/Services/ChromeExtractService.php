<?php

namespace App\Services;

use App\Models\ChromeExtLog;
use App\Models\Material;
use App\Models\MaterialPriceHistory;
use App\Models\ParserSupplierCollectProfile;
use App\Models\UserMaterialLibrary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChromeExtractService
{
    /**
     * UTM and tracking query parameters to strip from URLs.
     */
    private const TRACKING_PARAMS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
        'yclid', 'gclid', 'fbclid', 'etext', 'ybaip', 'pm_source',
        'callibri', '_openstat', 'from', 'rs', 'rec',
    ];

    /**
     * Strip tracking/UTM parameters from URL and ensure it fits the DB column.
     */
    public static function cleanUrl(?string $url): ?string
    {
        if (!$url) return null;

        $parsed = parse_url($url);
        if (!$parsed || empty($parsed['host'])) return mb_substr($url, 0, 2048);

        // Clean query string
        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $params);
            foreach (self::TRACKING_PARAMS as $p) {
                unset($params[$p]);
            }
            $parsed['query'] = !empty($params) ? http_build_query($params) : null;
        }

        // Rebuild URL
        $clean = ($parsed['scheme'] ?? 'https') . '://' . $parsed['host'];
        if (!empty($parsed['port'])) $clean .= ':' . $parsed['port'];
        $clean .= $parsed['path'] ?? '/';
        if (!empty($parsed['query'])) $clean .= '?' . $parsed['query'];
        if (!empty($parsed['fragment'])) $clean .= '#' . $parsed['fragment'];

        return mb_substr($clean, 0, 2048);
    }

    /**
     * Allowed unit values and their aliases mapping.
     */
    public const UNIT_MAP = [
        // Exact
        'м²'    => 'м²',
        'м.п.'  => 'м.п.',
        'шт'    => 'шт',
        'шт.'   => 'шт',
        'м кв'  => 'м²',
        'м кв.' => 'м²',
        'кв.м'  => 'м²',
        'кв.м.' => 'м²',
        'кв м'  => 'м²',
        'м2'    => 'м²',
        'м.п'   => 'м.п.',
        'мп'    => 'м.п.',
        'м.пог' => 'м.п.',
        'м.пог.'=> 'м.п.',
        'пог.м' => 'м.п.',
        'пог.м.'=> 'м.п.',
        'пог м' => 'м.п.',
        'штука' => 'шт',
        'штук'  => 'шт',
        'шт'    => 'шт',
        'пара'  => 'шт',
        'компл' => 'шт',
        'компл.'=> 'шт',
        'комплект' => 'шт',
        'набор' => 'шт',
        'упак'  => 'шт',
        'упак.' => 'шт',
        'упаковка' => 'шт',
        'лист'  => 'шт',
        'рулон' => 'шт',
    ];

    public const VALID_UNITS = ['м²', 'м.п.', 'шт'];

    /**
     * Sheet material name patterns — unit is always м² for these.
     */
    public const SHEET_MATERIAL_PATTERNS = [
        'ЛДСП', 'МДФ', 'ХДФ', 'ОСБ', 'ЛМДФ', 'OSB', 'ДВПО',
        'ДСП', 'ДВП', 'ЛХДФ', 'ЛОСБ', 'HPL', 'CPL', 'ФСФ', 'ФК',
    ];

    /**
     * Edge material name patterns — type = 'edge', unit = 'м.п.'
     */
    public const EDGE_NAME_PATTERNS = ['кромка', 'Кромка', 'КРОМКА'];

    /**
     * Edge material URL patterns (case-insensitive).
     */
    public const EDGE_URL_PATTERNS = ['kromka'];

    /**
     * Detect if material name indicates a sheet material.
     */
    public static function isSheetMaterial(string $name): bool
    {
        foreach (self::SHEET_MATERIAL_PATTERNS as $pattern) {
            if (mb_stripos($name, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Detect if material is an edge banding by name or URL.
     */
    public static function isEdgeMaterial(string $name, ?string $url = null): bool
    {
        // Check name
        if (mb_stripos($name, 'кромка') !== false) {
            return true;
        }
        // Check URL
        if ($url && stripos($url, 'kromka') !== false) {
            return true;
        }
        return false;
    }

    /**
     * Detect material type from name and URL.
     *
     * Priority:
     *  1. Edge — name contains "Кромка" or URL contains "kromka"
     *  2. Plate — name contains sheet material keywords (ЛДСП, МДФ, etc.)
     *  3. Hardware — fallback (фурнитура)
     *
     * @return string 'plate'|'edge'|'hardware'
     */
    public static function detectMaterialType(string $name, ?string $url = null): string
    {
        if (self::isEdgeMaterial($name, $url)) {
            return 'edge';
        }
        if (self::isSheetMaterial($name)) {
            return 'plate';
        }
        return 'hardware';
    }

    /**
     * Auto-detect unit from material type.
     * plate → м², edge → м.п., hardware → шт
     */
    public static function detectUnit(string $name, ?string $url = null): string
    {
        $type = self::detectMaterialType($name, $url);
        return match ($type) {
            'edge' => 'м.п.',
            'plate' => 'м²',
            default => 'шт',
        };
    }

    /**
     * Parse edge banding dimensions from name.
     * Edge format: "19х0,4" or "19x0.4" (width_mm × thickness_mm).
     * By convention: width → length_mm, thickness → width_mm in DB.
     *
     * Examples:
     *   "Кромка ПВХ 19х0,4 мм" → [length_mm => 19, width_mm => 0.4]
     *   "Кромка 22x2" → [length_mm => 22, width_mm => 2]
     *   "kromka_pvkh_belaya_shagren_finnplast_2201_19kh0_4" (from URL context)
     */
    public static function parseEdgeDimensionsFromName(string $name): array
    {
        $dims = ['length_mm' => null, 'width_mm' => null];

        // Pattern: WxT where W is 10-60 (edge width in mm), T is 0.1-5 (edge thickness in mm)
        // e.g. "19х0,4", "22x2", "44x1", "19х0.45"
        if (preg_match('/(\d{1,3})\s*[xхXХ×*]\s*(\d{1,2}(?:[.,]\d+)?)/u', $name, $m)) {
            $w = (float) $m[1];
            $t = (float) str_replace(',', '.', $m[2]);
            // Validate sensible edge dimensions
            if ($w >= 10 && $w <= 100 && $t > 0 && $t <= 10) {
                $dims['length_mm'] = (int) $w;      // edge width → length_mm (DB convention)
                $dims['width_mm'] = round($t, 2);   // edge thickness → width_mm (DB convention)
            }
        }

        return $dims;
    }

    /**
     * Parse dimensions (length × width, thickness) from material name.
     * Examples:
     *   "ЛДСП Кремовый 100 ГМ 2750*1830 16 мм КР" → [2750, 1830, 16]
     *   "МДФ 2800х2070х16" → [2800, 2070, 16]
     */
    public static function parseDimensionsFromName(string $name): array
    {
        $dims = ['length_mm' => null, 'width_mm' => null, 'thickness_mm' => null];

        // LxWxT (e.g. "2800х2070х16", "2750*1830*16")
        if (preg_match('/(\d{3,5})\s*[xхXХ×*]\s*(\d{3,5})\s*[xхXХ×*]\s*(\d{1,3}(?:[.,]\d+)?)/u', $name, $m)) {
            $dims['length_mm'] = (int) $m[1];
            $dims['width_mm'] = (int) $m[2];
            $dims['thickness_mm'] = (int) round((float) str_replace(',', '.', $m[3]));
            return $dims;
        }

        // LxW (e.g. "2750*1830")
        if (preg_match('/(\d{3,5})\s*[xхXХ×*]\s*(\d{3,5})/u', $name, $m)) {
            $dims['length_mm'] = (int) $m[1];
            $dims['width_mm'] = (int) $m[2];
        }

        // Standalone thickness: "16 мм", "3.2 мм"
        if ($dims['thickness_mm'] === null) {
            if (preg_match('/(?:^|\s|[,;])(\d{1,3}(?:[.,]\d+)?)\s*мм\b/ui', $name, $m)) {
                $t = (float) str_replace(',', '.', $m[1]);
                if ($t >= 2 && $t <= 50) {
                    $dims['thickness_mm'] = (int) round($t);
                }
            }
        }

        return $dims;
    }

    /**
     * Normalize unit value to standard form.
     */
    public static function normalizeUnit(?string $raw): ?string
    {
        if (!$raw) return null;
        $raw = mb_strtolower(trim($raw), 'UTF-8');
        return self::UNIT_MAP[$raw] ?? null;
    }

    /**
     * Parse price string to decimal number.
     * Handles: "1 234.56", "1234,56", "1 234,56 ₽", "от 1234 руб", etc.
     */
    public static function parsePrice(?string $raw): ?float
    {
        if (!$raw) return null;

        // Remove currency symbols and text
        $cleaned = preg_replace('/[₽$€¥£]/', '', $raw);
        $cleaned = preg_replace('/\b(руб|рублей|рубля|р|rub|usd|eur|RUB)\b\.?/ui', '', $cleaned);
        $cleaned = preg_replace('/\b(от|до|от\s+|до\s+|цена|price|за\s+шт|\/шт|\/м2|\/м\.п\.)\b/ui', '', $cleaned);
        $cleaned = trim($cleaned);

        // Handle thousand separators: "1 234.56" or "1 234,56"
        // If both , and . exist: figure out which is decimal
        if (preg_match('/^[\d\s]+[,.][\d]{1,2}$/', $cleaned)) {
            // Last separator is decimal
            $cleaned = preg_replace('/\s/', '', $cleaned);
            $cleaned = str_replace(',', '.', $cleaned);
        } else {
            // Remove spaces (thousand separators)
            $cleaned = preg_replace('/\s/', '', $cleaned);
            // Replace comma with dot
            $cleaned = str_replace(',', '.', $cleaned);
            // If multiple dots, keep only last as decimal
            $dotCount = substr_count($cleaned, '.');
            if ($dotCount > 1) {
                $parts = explode('.', $cleaned);
                $decimal = array_pop($parts);
                $cleaned = implode('', $parts) . '.' . $decimal;
            }
        }

        // Extract number
        if (preg_match('/(\d+\.?\d*)/', $cleaned, $m)) {
            $val = (float) $m[1];
            return $val > 0 ? $val : null;
        }

        return null;
    }

    /**
     * Parse currency from raw price string.
     */
    public static function parseCurrency(?string $raw): string
    {
        if (!$raw) return 'RUB';

        if (preg_match('/[$]|usd/ui', $raw)) return 'USD';
        if (preg_match('/[€]|eur/ui', $raw)) return 'EUR';
        // Default Russian
        return 'RUB';
    }

    /**
     * Extract domain from URL.
     */
    public static function extractDomain(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) return null;
        return preg_replace('/^www\./', '', strtolower($host));
    }

    /**
     * Find matching template for a URL.
     */
    public function findTemplate(string $url, ?int $userId = null): ?ParserSupplierCollectProfile
    {
        $domain = self::extractDomain($url);
        if (!$domain) return null;

        // Try user-specific templates first, then system defaults
        $query = ParserSupplierCollectProfile::where('supplier_name', $domain)
            ->where('source', 'chrome_ext')
            ->orderByRaw('CASE WHEN user_id = ? THEN 0 ELSE 1 END', [$userId ?? 0])
            ->orderByDesc('is_default')
            ->orderByDesc('version');

        $templates = $query->get();

        foreach ($templates as $template) {
            if ($this->matchesUrlPattern($url, $template->url_patterns)) {
                return $template;
            }
        }

        // Fallback: any template for this domain
        return $templates->first();
    }

    /**
     * Check if URL matches any of the template's patterns.
     */
    protected function matchesUrlPattern(string $url, ?array $patterns): bool
    {
        if (empty($patterns)) return true; // No patterns = matches all on this domain

        foreach ($patterns as $pattern) {
            if (isset($pattern['regex'])) {
                if (preg_match('/' . str_replace('/', '\/', $pattern['regex']) . '/i', $url)) {
                    return true;
                }
            }
            if (isset($pattern['path_contains'])) {
                if (str_contains($url, $pattern['path_contains'])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Validate extracted fields. Returns array of errors.
     *
     * @param array $fields Extracted fields (title, price, article, thickness, length, width)
     * @param string|null $url Source URL (used for edge detection)
     */
    public function validateExtractedFields(array $fields, ?string $url = null): array
    {
        $errors = [];

        // Title required
        if (empty($fields['title'])) {
            $errors[] = 'Название материала обязательно';
        }

        // Price must parse to number
        if (!empty($fields['price'])) {
            $price = self::parsePrice($fields['price']);
            if ($price === null) {
                $errors[] = 'Цена не распознана как число: ' . $fields['price'];
            }
        } else {
            $errors[] = 'Цена обязательна';
        }

        // Determine material type
        $title = trim($fields['title'] ?? '');
        $materialType = $title ? self::detectMaterialType($title, $url) : 'hardware';

        if ($materialType === 'plate') {
            // Plate: dimensions required
            $parsedDims = $title ? self::parseDimensionsFromName($title) : [];
            $hasThickness = !empty($fields['thickness']) || !empty($parsedDims['thickness_mm']);
            $hasLength = !empty($fields['length']) || !empty($parsedDims['length_mm']);
            $hasWidth = !empty($fields['width']) || !empty($parsedDims['width_mm']);

            if (!$hasThickness) {
                $errors[] = 'Толщина не указана и не распознана из названия';
            }
            if (!$hasLength || !$hasWidth) {
                $errors[] = 'Размеры (длина × ширина) не указаны и не распознаны из названия';
            }
        }
        // Edge and hardware: dimensions are optional — no validation errors for them

        return $errors;
    }

    /**
     * Determine trust level for extracted data.
     *
     * @param string|null $url Source URL (used for edge/hardware type detection)
     */
    public function determineTrustLevel(array $fields, array $errors, array $dataSources = [], ?string $url = null): array
    {
        if (!empty($errors)) {
            return [
                'is_verified' => false,
                'trust_level' => Material::TRUST_UNVERIFIED,
            ];
        }

        $hasTitle = !empty($fields['title']);
        $hasPrice = !empty($fields['price']);
        $hasArticle = !empty($fields['article']);

        $title = trim($fields['title'] ?? '');
        $materialType = $title ? self::detectMaterialType($title, $url) : 'hardware';

        // For hardware: dimensions are not needed for trust
        // For edge: only edge dims (length, width) matter
        // For plate: all 3 dims needed
        $hasDims = false;
        if ($materialType === 'hardware') {
            // Hardware doesn't need dimensions — treat as if dims are present
            $hasDims = true;
        } elseif ($materialType === 'edge') {
            $parsedEdge = self::parseEdgeDimensionsFromName($title);
            $hasDims = (!empty($fields['length']) || !empty($parsedEdge['length_mm']))
                && (!empty($fields['width']) || !empty($parsedEdge['width_mm']));
        } else {
            $parsedDims = self::parseDimensionsFromName($title);
            $hasDims = (!empty($fields['thickness']) || !empty($parsedDims['thickness_mm']))
                && (!empty($fields['length']) || !empty($parsedDims['length_mm']))
                && (!empty($fields['width']) || !empty($parsedDims['width_mm']));
        }

        // Check if any dimension was manually entered (not from auto/capture/schema)
        $dimFields = ['thickness', 'length', 'width'];
        $hasManualDims = false;
        if (!empty($dataSources)) {
            foreach ($dimFields as $df) {
                if (!empty($fields[$df]) && ($dataSources[$df] ?? '') === 'manual') {
                    $hasManualDims = true;
                    break;
                }
            }
        }

        // Full auto-collected data: VERIFIED
        // If dims are manually entered, cap at PARTIAL (manual dims don't elevate trust)
        if ($hasTitle && $hasPrice && $hasDims && $hasArticle && !$hasManualDims) {
            return [
                'is_verified' => true,
                'trust_level' => Material::TRUST_VERIFIED,
            ];
        }

        if ($hasTitle && $hasPrice && $hasDims) {
            return [
                'is_verified' => false,
                'trust_level' => Material::TRUST_PARTIAL,
            ];
        }

        if ($hasTitle && $hasPrice) {
            return [
                'is_verified' => false,
                'trust_level' => Material::TRUST_PARTIAL,
            ];
        }

        return [
            'is_verified' => false,
            'trust_level' => Material::TRUST_UNVERIFIED,
        ];
    }

    /**
     * Create or update material from chrome extension extracted data.
     *
     * @return array{material: Material, observation: MaterialPriceHistory, is_new: bool, dedup_match: ?string}
     */
    public function createOrUpdateMaterial(
        int $userId,
        string $url,
        array $extractedFields,
        ?int $regionId = null,
        ?int $templateId = null,
        array $dataSources = []
    ): array {
        $domain = self::extractDomain($url);
        $errors = $this->validateExtractedFields($extractedFields, $url);
        $trustInfo = $this->determineTrustLevel($extractedFields, $errors, $dataSources, $url);

        // Parse values
        $title = trim($extractedFields['title'] ?? '');
        $article = trim($extractedFields['article'] ?? '');
        $price = self::parsePrice($extractedFields['price'] ?? null);
        $currency = self::parseCurrency($extractedFields['price'] ?? null);

        // Detect material type and unit
        $materialType = self::detectMaterialType($title, $url);
        $unit = match ($materialType) {
            'edge' => 'м.п.',
            'plate' => 'м²',
            default => 'шт',
        };

        // Parse dimensions based on material type
        if ($materialType === 'edge') {
            // Edge: parse edge-specific dimensions (WxT where W→length_mm, T→width_mm by convention)
            $parsedEdge = self::parseEdgeDimensionsFromName($title);
            // For edge, use extracted fields if provided, else parsed from name
            $lengthMm = !empty($extractedFields['length'])
                ? (int) $extractedFields['length']
                : ($parsedEdge['length_mm'] ?? null);
            $edgeThickness = !empty($extractedFields['width'])
                ? (float) str_replace(',', '.', $extractedFields['width'])
                : ($parsedEdge['width_mm'] ?? null);
            // width_mm is int — store rounded value; also store precise value in `thickness` (decimal)
            $widthMm = $edgeThickness !== null ? max(1, (int) round($edgeThickness)) : null;
            $thicknessMm = null; // Not used for edges
            $thicknessDecimal = $edgeThickness !== null ? round($edgeThickness, 2) : null;
        } elseif ($materialType === 'hardware') {
            // Hardware: no dimensions
            $thicknessMm = null;
            $lengthMm = null;
            $widthMm = null;
            $thicknessDecimal = null;
        } else {
            // Plate: use extracted fields first, fallback to name parsing
            $parsedDims = self::parseDimensionsFromName($title);
            $thicknessMm = !empty($extractedFields['thickness'])
                ? (int) round((float) str_replace(',', '.', $extractedFields['thickness']))
                : ($parsedDims['thickness_mm'] ?? null);
            $lengthMm = !empty($extractedFields['length'])
                ? (int) $extractedFields['length']
                : ($parsedDims['length_mm'] ?? null);
            $widthMm = !empty($extractedFields['width'])
                ? (int) $extractedFields['width']
                : ($parsedDims['width_mm'] ?? null);
            $thicknessDecimal = $thicknessMm ? round($thicknessMm, 2) : null;
        }

        // If critical errors and no price, log and fail
        if (!$title || $price === null) {
            $this->logAction($userId, $url, $domain, 'extract', 'failed', $extractedFields, $errors, $templateId);
            return [
                'material' => null,
                'observation' => null,
                'is_new' => false,
                'dedup_match' => null,
                'errors' => $errors,
                'status' => 'failed',
            ];
        }

        // Deduplication
        $dedupService = new MaterialDeduplicationService();
        $normalizedUrl = MaterialDeduplicationService::normalizeUrl($url);
        $cleanedUrl = self::cleanUrl($url);
        $candidates = $dedupService->findDuplicates($url, $article, $title, $unit, $materialType);

        $isNew = true;
        $dedupMatch = null;
        $material = null;

        return DB::transaction(function () use (
            $userId, $url, $normalizedUrl, $cleanedUrl, $domain, $title, $article, $unit, $price, $currency,
            $thicknessMm, $lengthMm, $widthMm, $thicknessDecimal, $materialType,
            $regionId, $templateId, $trustInfo, $extractedFields, $errors, $dataSources,
            $candidates, &$isNew, &$dedupMatch
        ) {
            // Check for high-confidence match
            $highMatch = $candidates->where('confidence', 'high')->first();

            if ($highMatch) {
                // Update existing material
                $material = $highMatch['material'];
                $isNew = false;
                $dedupMatch = $highMatch['reason'];

                // Update fields if they improve the record
                if ($article && !$material->article) {
                    $material->article = $article;
                }
                if ($normalizedUrl && !$material->source_url) {
                    $material->source_url = $cleanedUrl ?: $normalizedUrl;
                }

                // Update dimensions if provided
                if ($thicknessMm && !$material->thickness_mm) {
                    $material->thickness_mm = $thicknessMm;
                    $material->thickness = $thicknessDecimal;
                }
                if ($lengthMm && !$material->length_mm) {
                    $material->length_mm = $lengthMm;
                }
                if ($widthMm && !$material->width_mm) {
                    $material->width_mm = $widthMm;
                }

                $material->unit = $unit;
                $material->price_per_unit = $price;
                $material->price_checked_at = now();
                $material->last_parsed_at = now();
                $material->last_parse_status = Material::PARSE_OK;
                $material->data_origin = Material::ORIGIN_CHROME_EXT;

                // Store data_sources in metadata
                if (!empty($dataSources)) {
                    $meta = $material->metadata ?? [];
                    $meta['field_sources'] = $dataSources;
                    $material->metadata = $meta;
                }

                $material->save();
            } else {
                // Create new material
                $material = Material::create([
                    'user_id' => $userId,
                    'origin' => 'parser',
                    'name' => $title,
                    'article' => $article,
                    'type' => $materialType,
                    'unit' => $unit,
                    'price_per_unit' => $price,
                    'source_url' => $cleanedUrl ?: ($normalizedUrl ?? $url),
                    'thickness' => $thicknessDecimal,
                    'thickness_mm' => $thicknessMm,
                    'length_mm' => $lengthMm,
                    'width_mm' => $widthMm,
                    'is_active' => true,
                    'version' => 1,
                    'data_origin' => Material::ORIGIN_CHROME_EXT,
                    'trust_level' => $trustInfo['trust_level'],
                    'trust_score' => $trustInfo['is_verified'] ? 70 : 30,
                    'last_parsed_at' => now(),
                    'last_parse_status' => Material::PARSE_OK,
                    'region_id' => $regionId,
                    'visibility' => Material::VISIBILITY_PRIVATE,
                    'price_checked_at' => now(),
                    'metadata' => !empty($dataSources) ? ['field_sources' => $dataSources] : null,
                ]);
            }

            // Create observation (price history entry)
            $observation = MaterialPriceHistory::create([
                'material_id' => $material->id,
                'version' => $material->version ?? 1,
                'valid_from' => now()->toDateString(),
                'price_per_unit' => $price,
                'source_url' => $cleanedUrl ?: $url,
                'region_id' => $regionId,
                'observed_at' => now(),
                'source_type' => MaterialPriceHistory::SOURCE_CHROME_EXT,
                'is_verified' => $trustInfo['is_verified'],
                'currency' => $currency,
            ]);

            // Add to user's library
            UserMaterialLibrary::firstOrCreate(
                ['user_id' => $userId, 'material_id' => $material->id],
                ['preferred_region_id' => $regionId]
            );

            // Log success
            $status = empty($errors) ? 'success' : 'partial';
            $this->logAction($userId, $url, $domain, 'extract', $status, $extractedFields, $errors, $templateId, $material->id);

            return [
                'material' => $material->fresh(),
                'observation' => $observation,
                'is_new' => $isNew,
                'dedup_match' => $dedupMatch,
                'errors' => $errors,
                'status' => $status,
            ];
        });
    }

    /**
     * Save or update a chrome extension template.
     */
    public function saveTemplate(
        int $userId,
        string $domain,
        string $name,
        array $selectors,
        ?array $urlPatterns = null,
        ?array $extractionRules = null,
        ?array $validationRules = null,
        ?array $testCase = null,
        bool $isDefault = false
    ): ParserSupplierCollectProfile {
        // Check for existing template with same name + domain + user
        $existing = ParserSupplierCollectProfile::where('supplier_name', $domain)
            ->where('user_id', $userId)
            ->where('name', $name)
            ->first();

        if ($existing) {
            // Increment version
            $existing->selectors = $selectors;
            $existing->url_patterns = $urlPatterns;
            $existing->extraction_rules = $extractionRules;
            $existing->validation_rules = $validationRules;
            $existing->test_case = $testCase;
            $existing->version = $existing->version + 1;

            // Build config_override from selectors for backward compatibility
            $existing->config_override = $this->buildConfigOverride($selectors, $urlPatterns, $extractionRules);
            $existing->save();

            $this->logAction($userId, $domain, $domain, 'save_template', 'success', [
                'template_id' => $existing->id,
                'version' => $existing->version,
            ]);

            return $existing;
        }

        // Reset other defaults if this is default
        if ($isDefault) {
            ParserSupplierCollectProfile::where('supplier_name', $domain)
                ->where('user_id', $userId)
                ->update(['is_default' => false]);
        }

        $profile = ParserSupplierCollectProfile::create([
            'supplier_name' => $domain,
            'name' => $name,
            'user_id' => $userId,
            'selectors' => $selectors,
            'url_patterns' => $urlPatterns,
            'extraction_rules' => $extractionRules,
            'validation_rules' => $validationRules,
            'test_case' => $testCase,
            'config_override' => $this->buildConfigOverride($selectors, $urlPatterns, $extractionRules),
            'is_default' => $isDefault,
            'source' => 'chrome_ext',
            'version' => 1,
        ]);

        $this->logAction($userId, $domain, $domain, 'save_template', 'success', [
            'template_id' => $profile->id,
            'version' => 1,
        ]);

        return $profile;
    }

    /**
     * Build config_override JSON for backward compatibility with parser system.
     */
    protected function buildConfigOverride(array $selectors, ?array $urlPatterns, ?array $extractionRules): array
    {
        return [
            'source' => 'chrome_ext',
            'selectors' => $selectors,
            'url_patterns' => $urlPatterns ?? [],
            'extraction_rules' => $extractionRules ?? [],
        ];
    }

    /**
     * Log chrome extension action.
     */
    protected function logAction(
        int $userId,
        string $url,
        ?string $domain,
        string $action,
        string $status,
        ?array $extractedFields = null,
        ?array $errors = null,
        ?int $templateId = null,
        ?int $materialId = null
    ): void {
        try {
            ChromeExtLog::create([
                'user_id' => $userId,
                'url' => mb_substr($url, 0, 2048),
                'domain' => $domain ?? self::extractDomain($url) ?? 'unknown',
                'action' => $action,
                'status' => $status,
                'extracted_fields' => $extractedFields,
                'errors' => $errors,
                'template_id' => $templateId,
                'material_id' => $materialId,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Chrome ext log failed', [
                'error' => $e->getMessage(),
                'action' => $action,
                'url' => $url,
            ]);
        }
    }
}
