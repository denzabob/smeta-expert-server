<?php

namespace App\Services\PriceImport;

use App\Models\Operation;
use App\Models\SupplierProductAlias;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Сервис сопоставления строк прайса с базовыми операциями.
 * 
 * Порядок поиска:
 * 1. Alias (память соответствий из SupplierProductAlias)
 * 2. Exact match по normalized search_name
 * 3. Fuzzy через pg_trgm/FULLTEXT (top N кандидатов)
 * 4. Levenshtein уточнение на топ-кандидатах
 * 
 * ВАЖНО: Не используем O(N*M) перебор всех операций.
 * Fuzzy поиск через индексы БД, Levenshtein только для top-10.
 */
class OperationMatchingService
{
    /**
     * Минимальный порог сходства для fuzzy matching
     */
    private const FUZZY_THRESHOLD = 0.3;

    /**
     * Порог для автоматического fuzzy match
     */
    private const AUTO_FUZZY_THRESHOLD = 0.7;

    /**
     * Максимум кандидатов для fuzzy
     */
    private const MAX_FUZZY_CANDIDATES = 10;

    /**
     * Найти соответствие для строки прайса.
     * 
     * @param string $sourceName Название из прайса поставщика
     * @param string|null $externalKey Артикул/SKU (если есть)
     * @param int $supplierId ID поставщика
     * @param int $userId ID пользователя (для фильтрации операций)
     * @return MatchResult
     */
    public function match(
        string $sourceName,
        ?string $externalKey,
        int $supplierId,
        int $userId
    ): MatchResult {
        // 1. Проверка alias в SupplierProductAlias
        $alias = $this->findAlias($sourceName, $externalKey, $supplierId);
        if ($alias) {
            return MatchResult::alias($alias->internal_item_id, [
                'alias_id' => $alias->id,
                'conversion_factor' => $alias->conversion_factor,
                'supplier_unit' => $alias->supplier_unit,
                'internal_unit' => $alias->internal_unit,
                'confidence' => $alias->confidence,
            ]);
        }

        // 2. Exact match по normalized search_name
        $normalized = Operation::normalizeSearchName($sourceName);
        $exactMatch = $this->findExact($normalized, $userId);
        if ($exactMatch && $this->isSemanticallyConsistent($sourceName, $exactMatch->name)) {
            return MatchResult::exact($exactMatch);
        }

        // 3. Fuzzy через pg_trgm/FULLTEXT (top N кандидатов)
        $candidates = $this->findFuzzyCandidates($normalized, $userId, self::MAX_FUZZY_CANDIDATES);
        $candidates = $candidates
            ->filter(fn($operation) => $this->isSemanticallyConsistent($sourceName, $operation->name))
            ->values();

        if ($candidates->isEmpty()) {
            return MatchResult::notFound($sourceName);
        }

        // 4. Уточнение через Levenshtein на топ-кандидатах
        $bestMatch = $this->refineFuzzyMatch($normalized, $candidates);

        if ($bestMatch && $bestMatch['similarity'] >= self::AUTO_FUZZY_THRESHOLD) {
            return MatchResult::fuzzy($bestMatch['operation'], $bestMatch['similarity']);
        }

        // 5. Возврат кандидатов для ручного выбора
        return MatchResult::ambiguous($candidates->toArray(), $sourceName);
    }

    /**
     * Поиск alias в памяти соответствий.
     */
    private function findAlias(string $sourceName, ?string $externalKey, int $supplierId): ?SupplierProductAlias
    {
        $query = SupplierProductAlias::where('supplier_id', $supplierId)
            ->where('internal_item_type', 'operation');

        // Сначала по external_key если есть
        if ($externalKey) {
            $alias = (clone $query)->where('external_key', $externalKey)->first();
            if ($alias) {
                if (!$this->isAliasSemanticallyConsistent($sourceName, $alias->internal_item_id)) {
                    return null;
                }
                // Обновляем last_seen_at и usage_count
                $alias->update([
                    'last_seen_at' => now(),
                    'usage_count' => DB::raw('usage_count + 1'),
                ]);
                return $alias;
            }
        }

        // Потом по normalized external_name
        $normalizedName = Operation::normalizeSearchName($sourceName);
        $alias = $query->where(function ($q) use ($normalizedName, $sourceName) {
            // Проверяем и нормализованное и оригинальное имя
            $q->whereRaw('LOWER(external_name) = ?', [mb_strtolower($sourceName)])
              ->orWhere('external_key', md5($normalizedName));
        })->first();

        if ($alias) {
            if (!$this->isAliasSemanticallyConsistent($sourceName, $alias->internal_item_id)) {
                return null;
            }
            $alias->update([
                'last_seen_at' => now(),
                'usage_count' => DB::raw('usage_count + 1'),
            ]);
        }

        return $alias;
    }

    /**
     * Точный поиск по normalized search_name.
     */
    private function findExact(string $normalizedName, int $userId): ?Operation
    {
        return Operation::where('search_name', $normalizedName)
            ->where(function ($q) use ($userId) {
                $q->whereNull('user_id')
                  ->orWhere('user_id', $userId);
            })
            ->first();
    }

    /**
     * Fuzzy поиск через индекс БД (pg_trgm для PostgreSQL, FULLTEXT для MySQL).
     */
    private function findFuzzyCandidates(string $normalizedName, int $userId, int $limit): Collection
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            return $this->findFuzzyPostgres($normalizedName, $userId, $limit);
        }

        // MySQL fallback с LIKE и SOUNDEX
        return $this->findFuzzyMysql($normalizedName, $userId, $limit);
    }

    /**
     * PostgreSQL fuzzy поиск через pg_trgm.
     */
    private function findFuzzyPostgres(string $normalizedName, int $userId, int $limit): Collection
    {
        return Operation::select('*')
            ->selectRaw('similarity(search_name, ?) as similarity_score', [$normalizedName])
            ->whereRaw('similarity(search_name, ?) > ?', [$normalizedName, self::FUZZY_THRESHOLD])
            ->where(function ($q) use ($userId) {
                $q->whereNull('user_id')
                  ->orWhere('user_id', $userId);
            })
            ->orderByRaw('similarity(search_name, ?) DESC', [$normalizedName])
            ->limit($limit)
            ->get();
    }

    /**
     * MySQL fuzzy поиск через LIKE и частичное совпадение.
     */
    private function findFuzzyMysql(string $normalizedName, int $userId, int $limit): Collection
    {
        // Разбиваем на слова для поиска
        $words = array_filter(explode(' ', $normalizedName), fn($w) => mb_strlen($w) >= 3);
        
        if (empty($words)) {
            // Если слов нет, ищем по LIKE
            return Operation::where('search_name', 'LIKE', "%{$normalizedName}%")
                ->where(function ($q) use ($userId) {
                    $q->whereNull('user_id')
                      ->orWhere('user_id', $userId);
                })
                ->limit($limit)
                ->get();
        }

        // Ищем операции, содержащие хотя бы одно из слов
        return Operation::where(function ($q) use ($words) {
                foreach ($words as $word) {
                    $q->orWhere('search_name', 'LIKE', "%{$word}%");
                }
            })
            ->where(function ($q) use ($userId) {
                $q->whereNull('user_id')
                  ->orWhere('user_id', $userId);
            })
            ->limit($limit)
            ->get()
            ->sortByDesc(function ($op) use ($words) {
                // Сортируем по количеству совпадающих слов
                $matches = 0;
                foreach ($words as $word) {
                    if (str_contains($op->search_name, $word)) {
                        $matches++;
                    }
                }
                return $matches;
            })
            ->values();
    }

    /**
     * Уточнение fuzzy match через Levenshtein.
     */
    private function refineFuzzyMatch(string $normalizedName, Collection $candidates): ?array
    {
        $bestMatch = null;
        $bestSimilarity = 0;

        foreach ($candidates as $operation) {
            $similarity = $this->calculateSimilarity($normalizedName, $operation->search_name);
            
            if ($similarity > $bestSimilarity) {
                $bestSimilarity = $similarity;
                $bestMatch = [
                    'operation' => $operation,
                    'similarity' => $similarity,
                ];
            }
        }

        return $bestMatch;
    }

    /**
     * Расчёт сходства строк (0.0 - 1.0).
     * Использует Levenshtein distance, нормализованный по длине.
     */
    private function calculateSimilarity(string $s1, string $s2): float
    {
        if ($s1 === $s2) {
            return 1.0;
        }

        $maxLen = max(mb_strlen($s1), mb_strlen($s2));
        if ($maxLen === 0) {
            return 1.0;
        }

        // Для очень длинных строк ограничиваем вычисления
        if ($maxLen > 255) {
            $s1 = mb_substr($s1, 0, 255);
            $s2 = mb_substr($s2, 0, 255);
            $maxLen = 255;
        }

        $distance = levenshtein($s1, $s2);
        
        return 1.0 - ($distance / $maxLen);
    }

    /**
     * Сохранить alias после ручного связывания.
     */
    public function saveAlias(
        int $operationId,
        int $supplierId,
        string $sourceName,
        ?string $externalKey,
        ?string $supplierUnit,
        ?string $internalUnit,
        float $conversionFactor = 1.0
    ): SupplierProductAlias {
        // Генерируем external_key если не задан
        $key = $externalKey ?? md5(Operation::normalizeSearchName($sourceName));

        return SupplierProductAlias::updateOrCreate(
            [
                'supplier_id' => $supplierId,
                'external_key' => $key,
                'internal_item_type' => 'operation',
            ],
            [
                'external_name' => $sourceName,
                'internal_item_id' => $operationId,
                'supplier_unit' => $supplierUnit,
                'internal_unit' => $internalUnit,
                'conversion_factor' => $conversionFactor,
                'confidence' => 'manual',
                'first_seen_at' => DB::raw('COALESCE(first_seen_at, NOW())'),
                'last_seen_at' => now(),
                'usage_count' => DB::raw('usage_count + 1'),
            ]
        );
    }

    /**
     * Пакетное сопоставление для нескольких строк.
     * 
     * @param array $items Массив строк с ключами: source_name, external_key
     * @param int $supplierId
     * @param int $userId
     * @return array Массив MatchResult
     */
    public function matchBatch(array $items, int $supplierId, int $userId): array
    {
        $results = [];

        foreach ($items as $index => $item) {
            $results[$index] = $this->match(
                $item['source_name'] ?? $item['name'] ?? '',
                $item['external_key'] ?? $item['article'] ?? null,
                $supplierId,
                $userId
            );
        }

        return $results;
    }

    private function isAliasSemanticallyConsistent(string $sourceName, int $operationId): bool
    {
        $operation = Operation::find($operationId);
        if (!$operation) {
            return false;
        }

        return $this->isSemanticallyConsistent($sourceName, $operation->name);
    }

    private function isSemanticallyConsistent(string $sourceName, string $targetName): bool
    {
        $source = Operation::normalizeSearchName($sourceName);
        $target = Operation::normalizeSearchName($targetName);
        if ($source === '' || $target === '') {
            return false;
        }

        $markers = ['распил', 'кромкооблицов', 'криволин', 'прямолин', 'отверст', 'глян', 'покрыт'];
        foreach ($markers as $marker) {
            $sourceHas = mb_strpos($source, $marker) !== false;
            $targetHas = mb_strpos($target, $marker) !== false;
            if ($sourceHas !== $targetHas) {
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
