<?php

namespace App\Services\PriceImport;

use App\Models\Material;
use App\Models\Operation;
use App\Models\PriceImportSession;
use App\Models\SupplierProductAlias;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Сервис поиска кандидатов и matching для импорта прайсов
 * 
 * Использует комбинацию:
 * 1. Точный match по SKU/alias
 * 2. Trigram similarity для fuzzy search (без pg_trgm - на уровне PHP)
 * 3. Levenshtein для финального ранжирования
 */
class CandidateMatchingService
{
    private const DEFAULT_TOP_K = 10;
    private const DEFAULT_THRESHOLD = 0.4;
    private const EXACT_MATCH_THRESHOLD = 0.95;
    private const AUTO_ACCEPT_THRESHOLD = 0.85;

    private float $threshold;
    private int $topK;

    public function __construct(float $threshold = self::DEFAULT_THRESHOLD, int $topK = self::DEFAULT_TOP_K)
    {
        $this->threshold = $threshold;
        $this->topK = $topK;
    }

    /**
     * Match single row against internal catalog.
     */
    public function matchRow(array $row, PriceImportSession $session): array
    {
        $name = $row['name'] ?? '';
        $sku = $row['sku'] ?? $row['article'] ?? null;
        $supplierId = $session->supplier_id;
        $targetType = $session->target_type;

        // 1. Try exact match by SKU/alias
        if ($sku && $supplierId) {
            $aliasMatch = $this->findByAlias($supplierId, $sku, $targetType);
            if ($aliasMatch) {
                if (!$this->isSemanticallyConsistent($name, $aliasMatch['item']['name'] ?? '')) {
                    // Poisoned/legacy alias: do not trust auto-match.
                    $aliasMatch = null;
                }
            }
            if ($aliasMatch) {
                return [
                    'status' => 'auto_matched',
                    'match_type' => 'alias',
                    'item' => $aliasMatch['item'],
                    'alias' => $aliasMatch['alias'],
                    'similarity' => 1.0,
                    'candidates' => [],
                ];
            }
        }

        // 2. Try exact match by name hash (existing alias)
        if ($supplierId) {
            $nameKey = SupplierProductAlias::generateExternalKey($name);
            $aliasMatch = $this->findByAlias($supplierId, $nameKey, $targetType);
            if ($aliasMatch) {
                if (!$this->isSemanticallyConsistent($name, $aliasMatch['item']['name'] ?? '')) {
                    // Poisoned/legacy alias: do not trust auto-match.
                    $aliasMatch = null;
                }
            }
            if ($aliasMatch) {
                return [
                    'status' => 'auto_matched',
                    'match_type' => 'alias_name',
                    'item' => $aliasMatch['item'],
                    'alias' => $aliasMatch['alias'],
                    'similarity' => 1.0,
                    'candidates' => [],
                ];
            }
        }

        // 3. Search candidates by fuzzy matching
        $candidates = $this->searchCandidates($name, $targetType);

        if (empty($candidates)) {
            return [
                'status' => 'new',
                'match_type' => 'none',
                'item' => null,
                'alias' => null,
                'similarity' => 0,
                'candidates' => [],
            ];
        }

        $topCandidate = $candidates[0];

        // 4. Auto-accept if high similarity
        if (
            $topCandidate['similarity'] >= self::AUTO_ACCEPT_THRESHOLD
            && $this->isSemanticallyConsistent($name, $topCandidate['name'] ?? '')
        ) {
            return [
                'status' => 'auto_matched',
                'match_type' => 'fuzzy_auto',
                'item' => $topCandidate,
                'alias' => null,
                'similarity' => $topCandidate['similarity'],
                'candidates' => $candidates,
            ];
        }

        // 5. Ambiguous - needs manual resolution
        return [
            'status' => 'ambiguous',
            'match_type' => 'fuzzy',
            'item' => null,
            'alias' => null,
            'similarity' => $topCandidate['similarity'],
            'candidates' => $candidates,
        ];
    }

    /**
     * Run dry-run matching for all rows.
     */
    public function dryRun(PriceImportSession $session): array
    {
        $rawRows = $session->raw_rows ?? [];
        $mapping = $session->column_mapping ?? [];
        
        if (empty($rawRows) || empty($mapping)) {
            throw new \InvalidArgumentException('Session has no parsed rows or mapping');
        }

        $stats = [
            'total' => 0,
            'auto_matched' => 0,
            'ambiguous' => 0,
            'new' => 0,
            'ignored' => 0,
            'errors' => 0,
        ];

        $resolutionQueue = [];
        $headerRowIndex = $session->header_row_index ?? 0;

        foreach ($rawRows as $rowIndex => $rawRow) {
            // Skip header row
            if ($rowIndex <= $headerRowIndex) {
                continue;
            }

            $stats['total']++;

            // Map row to fields
            $mappedRow = $this->mapRowToFields($rawRow, $mapping);
            
            // Skip if no name
            if (empty($mappedRow['name'])) {
                $stats['ignored']++;
                continue;
            }

            // For operations imports, skip zero/empty prices early:
            // these rows must not appear on resolution step and must not be imported.
            if ($session->target_type === PriceImportSession::TARGET_OPERATIONS) {
                $price = $mappedRow['cost_per_unit'] ?? null;
                if ($price === null || (float)$price <= 0.0) {
                    $stats['ignored']++;
                    continue;
                }
            }

            // Skip if ignore flag
            if (!empty($mappedRow['ignore'])) {
                $stats['ignored']++;
                continue;
            }

            try {
                $matchResult = $this->matchRow($mappedRow, $session);
                $stats[$matchResult['status']]++;

                // Add to resolution queue if not auto_matched
                if ($matchResult['status'] !== 'auto_matched') {
                    $resolutionQueue[] = [
                        'row_index' => $rowIndex,
                        'raw_data' => $mappedRow,
                        'status' => $matchResult['status'],
                        'candidates' => array_slice($matchResult['candidates'], 0, 5), // Limit candidates
                        'suggested' => $this->suggestResolution($mappedRow, $matchResult),
                    ];
                } else {
                    // Store auto-matched for execution
                    $matchedItem = $matchResult['item'] ?? null;
                    $resolutionQueue[] = [
                        'row_index' => $rowIndex,
                        'raw_data' => $mappedRow,
                        'status' => 'auto_matched',
                        'matched_item_id' => $matchedItem['id'] ?? null,
                        'matched_item_name' => $matchedItem['name'] ?? null,
                        'matched_item_unit' => $matchedItem['unit'] ?? null,
                        'matched_item_type' => $session->target_type,
                        'match_type' => $matchResult['match_type'],
                        'similarity' => $matchResult['similarity'],
                        'alias_id' => $matchResult['alias']['id'] ?? null,
                        'candidates' => $matchedItem ? [[
                            'id' => $matchedItem['id'],
                            'name' => $matchedItem['name'],
                            'unit' => $matchedItem['unit'] ?? null,
                            'category' => $matchedItem['category'] ?? null,
                            'similarity' => $matchResult['similarity'] ?? 1.0,
                            'match_method' => $matchResult['match_type'] ?? 'auto',
                        ]] : [],
                        'suggested' => null,
                    ];
                }
            } catch (\Exception $e) {
                $stats['errors']++;
                $resolutionQueue[] = [
                    'row_index' => $rowIndex,
                    'raw_data' => $mappedRow,
                    'status' => 'error',
                    'error' => $e->getMessage(),
                    'candidates' => [],
                    'suggested' => null,
                ];
            }
        }

        return [
            'session_id' => $session->id,
            'stats' => $stats,
            'resolution_queue' => $resolutionQueue,
        ];
    }

    /**
     * Find item by existing alias.
     */
    private function findByAlias(int $supplierId, string $externalKey, string $itemType): ?array
    {
        $internalType = $itemType === 'operations'
            ? SupplierProductAlias::TYPE_OPERATION
            : SupplierProductAlias::TYPE_MATERIAL;

        $alias = SupplierProductAlias::where('supplier_id', $supplierId)
            ->where('external_key', $externalKey)
            ->where('internal_item_type', $internalType)
            ->first();

        if (!$alias) {
            return null;
        }

        // Load the internal item
        $item = $itemType === 'operations' 
            ? Operation::find($alias->internal_item_id)
            : Material::find($alias->internal_item_id);

        if (!$item) {
            return null;
        }

        return [
            'alias' => $alias->toArray(),
            'item' => [
                'id' => $item->id,
                'name' => $item->name,
                'unit' => $item->unit,
                'category' => $item->category ?? null,
            ],
        ];
    }

    /**
     * Search candidates by fuzzy matching.
     */
    private function searchCandidates(string $searchName, string $targetType): array
    {
        $model = $targetType === 'operations' ? Operation::class : Material::class;
        
        // Get all items (for MVP - will optimize with pg_trgm later)
        // For production: use pg_trgm GIN index or search engine
        $items = $model::select(['id', 'name', 'search_name', 'unit', 'category'])
            ->whereNotNull('name')
            ->limit(5000) // Safety limit
            ->get();

        $normalizedSearch = TextNormalizer::normalize($searchName);
        $candidates = [];

        foreach ($items as $item) {
            $itemSearchName = $item->search_name ?? TextNormalizer::normalize($item->name);
            
            // Quick filter: skip if no common trigrams
            $similarity = TextNormalizer::combinedSimilarity($normalizedSearch, $itemSearchName);
            
            if ($similarity >= $this->threshold) {
                $candidates[] = [
                    'id' => $item->id,
                    'name' => $item->name,
                    'unit' => $item->unit,
                    'category' => $item->category,
                    'similarity' => round($similarity, 4),
                    'is_alias' => false,
                ];
            }
        }

        // Sort by similarity descending
        usort($candidates, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        // Return top K
        return array_slice($candidates, 0, $this->topK);
    }

    /**
     * Map raw row array to named fields based on column mapping.
     */
    private function mapRowToFields(array $rawRow, array $mapping): array
    {
        $result = [];
        
        foreach ($mapping as $columnIndex => $fieldName) {
            if ($fieldName && isset($rawRow[$columnIndex])) {
                $value = $rawRow[$columnIndex];
                
                // Parse price fields
                if (in_array($fieldName, ['price', 'cost_per_unit'])) {
                    $value = TextNormalizer::extractPrice((string) $value);
                }
                
                $result[$fieldName] = $value;
            }
        }

        return $result;
    }

    /**
     * Suggest resolution action based on match result.
     */
    private function suggestResolution(array $row, array $matchResult): ?array
    {
        if ($matchResult['status'] === 'new') {
            return [
                'action' => 'create',
                'internal_unit' => $row['unit'] ?? null,
                'conversion_factor' => 1.0,
            ];
        }

        if ($matchResult['status'] === 'ambiguous' && !empty($matchResult['candidates'])) {
            $top = $matchResult['candidates'][0];
            
            return [
                'action' => 'link',
                'suggested_item_id' => $top['id'],
                'internal_unit' => $top['unit'] ?? null,
                'conversion_factor' => $this->suggestConversionFactor($row['unit'] ?? null, $top['unit'] ?? null),
            ];
        }

        return null;
    }

    /**
     * Suggest conversion factor based on units.
     */
    private function suggestConversionFactor(?string $supplierUnit, ?string $internalUnit): float
    {
        if (!$supplierUnit || !$internalUnit) {
            return 1.0;
        }

        $supplierUnit = mb_strtolower(trim($supplierUnit));
        $internalUnit = mb_strtolower(trim($internalUnit));

        // Same units
        if ($supplierUnit === $internalUnit) {
            return 1.0;
        }

        // Common conversion patterns
        $patterns = [
            // упак. → шт
            ['упак', 'шт', 1.0], // Default, user should specify
            ['уп', 'шт', 1.0],
            ['пачка', 'шт', 1.0],
            ['коробка', 'шт', 1.0],
            ['компл', 'шт', 1.0],
            
            // м² → м²
            ['м2', 'м²', 1.0],
            ['кв.м', 'м²', 1.0],
            ['м.кв', 'м²', 1.0],
            
            // п.м. → п.м.
            ['пм', 'п.м.', 1.0],
            ['п.м', 'п.м.', 1.0],
            ['м.п', 'п.м.', 1.0],
        ];

        foreach ($patterns as [$pattern, $target, $factor]) {
            if (str_contains($supplierUnit, $pattern) && str_contains($internalUnit, $target)) {
                return $factor;
            }
        }

        return 1.0;
    }

    /**
     * Set similarity threshold.
     */
    public function setThreshold(float $threshold): self
    {
        $this->threshold = $threshold;
        return $this;
    }

    /**
     * Set top K candidates.
     */
    public function setTopK(int $topK): self
    {
        $this->topK = $topK;
        return $this;
    }

    private function isSemanticallyConsistent(string $sourceName, string $targetName): bool
    {
        $source = TextNormalizer::normalize($sourceName);
        $target = TextNormalizer::normalize($targetName);
        if ($source === '' || $target === '') {
            return false;
        }

        $markers = ['распил', 'кромкооблицов', 'криволин', 'прямолин', 'отверст', 'глян', 'покрыт'];
        foreach ($markers as $marker) {
            $s = mb_strpos($source, $marker) !== false;
            $t = mb_strpos($target, $marker) !== false;
            if ($s !== $t) {
                return false;
            }
        }

        $sourceDim = $this->extractDimOrDiameterToken($sourceName);
        $targetDim = $this->extractDimOrDiameterToken($targetName);
        if ($sourceDim !== null && $targetDim !== null && $sourceDim !== $targetDim) {
            return false;
        }

        return true;
    }

    private function extractDimOrDiameterToken(string $value): ?string
    {
        if (preg_match('/\b(\d+(?:[.,]\d+)?)\s*[xх]\s*(\d+(?:[.,]\d+)?)\b/u', $value, $m)) {
            $a = str_replace(',', '.', $m[1]);
            $b = str_replace(',', '.', $m[2]);
            return "dim:{$a}x{$b}";
        }

        if (preg_match('/(?:диаметром|d)\s*(\d+(?:[.,]\d+)?)\b/ui', $value, $m)) {
            $d = str_replace(',', '.', $m[1]);
            return "dia:{$d}";
        }

        return null;
    }
}
