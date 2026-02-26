<?php

namespace App\Services\PriceImport;

use App\Models\Operation;

/**
 * Результат сопоставления строки прайса с базовой операцией.
 */
class MatchResult
{
    public const STATUS_ALIAS = 'alias';
    public const STATUS_EXACT = 'exact';
    public const STATUS_FUZZY = 'fuzzy';
    public const STATUS_AMBIGUOUS = 'ambiguous';
    public const STATUS_NOT_FOUND = 'not_found';

    private function __construct(
        public readonly string $status,
        public readonly ?int $operationId,
        public readonly ?Operation $operation,
        public readonly ?float $similarity,
        public readonly ?array $aliasData,
        public readonly ?array $candidates,
        public readonly ?string $sourceName
    ) {}

    /**
     * Match found via alias (memory).
     */
    public static function alias(int $operationId, array $aliasData): self
    {
        return new self(
            status: self::STATUS_ALIAS,
            operationId: $operationId,
            operation: Operation::find($operationId),
            similarity: 1.0,
            aliasData: $aliasData,
            candidates: null,
            sourceName: null
        );
    }

    /**
     * Exact match by normalized name.
     */
    public static function exact(Operation $operation): self
    {
        return new self(
            status: self::STATUS_EXACT,
            operationId: $operation->id,
            operation: $operation,
            similarity: 1.0,
            aliasData: null,
            candidates: null,
            sourceName: null
        );
    }

    /**
     * Fuzzy match with high confidence.
     */
    public static function fuzzy(Operation $operation, float $similarity): self
    {
        return new self(
            status: self::STATUS_FUZZY,
            operationId: $operation->id,
            operation: $operation,
            similarity: $similarity,
            aliasData: null,
            candidates: null,
            sourceName: null
        );
    }

    /**
     * Multiple candidates found, needs manual resolution.
     */
    public static function ambiguous(array $candidates, string $sourceName): self
    {
        return new self(
            status: self::STATUS_AMBIGUOUS,
            operationId: null,
            operation: null,
            similarity: null,
            aliasData: null,
            candidates: $candidates,
            sourceName: $sourceName
        );
    }

    /**
     * No match found.
     */
    public static function notFound(string $sourceName): self
    {
        return new self(
            status: self::STATUS_NOT_FOUND,
            operationId: null,
            operation: null,
            similarity: null,
            aliasData: null,
            candidates: null,
            sourceName: $sourceName
        );
    }

    /**
     * Check if match was found automatically.
     */
    public function isAutoMatched(): bool
    {
        return in_array($this->status, [
            self::STATUS_ALIAS,
            self::STATUS_EXACT,
            self::STATUS_FUZZY,
        ]);
    }

    /**
     * Check if manual resolution is required.
     */
    public function needsResolution(): bool
    {
        return in_array($this->status, [
            self::STATUS_AMBIGUOUS,
            self::STATUS_NOT_FOUND,
        ]);
    }

    /**
     * Convert to array for API response.
     */
    public function toArray(): array
    {
        $result = [
            'status' => $this->status,
            'operation_id' => $this->operationId,
            'similarity' => $this->similarity,
        ];

        if ($this->operation) {
            $result['operation'] = [
                'id' => $this->operation->id,
                'name' => $this->operation->name,
                'category' => $this->operation->category,
                'unit' => $this->operation->unit,
                'exclusion_group' => $this->operation->exclusion_group,
            ];
        }

        if ($this->aliasData) {
            $result['alias'] = $this->aliasData;
        }

        // Для автоматически связанных операций формируем массив candidates с одним элементом
        if ($this->operation && in_array($this->status, [self::STATUS_ALIAS, self::STATUS_EXACT, self::STATUS_FUZZY])) {
            $result['candidates'] = [[
                'id' => $this->operation->id,
                'name' => $this->operation->name,
                'category' => $this->operation->category,
                'unit' => $this->operation->unit,
                'similarity' => $this->similarity,
                'match_method' => $this->status, // 'alias', 'exact', 'fuzzy'
            ]];
        } elseif ($this->candidates) {
            $result['candidates'] = array_map(function ($op) {
                if ($op instanceof Operation) {
                    return [
                        'id' => $op->id,
                        'name' => $op->name,
                        'category' => $op->category,
                        'unit' => $op->unit,
                        'similarity' => $op->similarity_score ?? null,
                        'match_method' => null, // для кандидатов ambiguous нет метода
                    ];
                }
                return $op;
            }, $this->candidates);
        }

        if ($this->sourceName) {
            $result['source_name'] = $this->sourceName;
        }

        return $result;
    }
}
