<?php

namespace App\Services\Material;

use App\Models\Material;

class MaterialCanonicalResolver
{
    /**
     * Find a material only when every canonical field matches exactly.
     */
    public function findExactMatch(array $payload): ?Material
    {
        $canonical = $this->canonicalize($payload);

        if ($canonical['type'] === null || $canonical['search_name'] === null) {
            return null;
        }

        $query = Material::query()
            ->where('is_active', true)
            ->where('type', $canonical['type'])
            ->where('search_name', $canonical['search_name']);

        $this->whereNullable($query, 'length_mm', $canonical['length_mm']);
        $this->whereNullable($query, 'width_mm', $canonical['width_mm']);
        $this->whereEffectiveThickness($query, $canonical['thickness_mm'], $canonical['thickness']);
        $this->whereNullable($query, 'user_id', $canonical['user_id']);
        $this->whereNullable($query, 'visibility', $canonical['visibility']);

        return $query->get()
            ->first(fn (Material $material): bool => $this->normalizeText($material->article) === $canonical['article']);
    }

    /**
     * @return array{material: Material, created: bool}
     */
    public function findOrCreate(array $payload): array
    {
        $existing = $this->findExactMatch($payload);

        if ($existing) {
            return ['material' => $existing, 'created' => false];
        }

        return ['material' => Material::query()->create($payload), 'created' => true];
    }

    /**
     * Build the strict comparison key used by the resolver and audit command.
     *
     * @return array{
     *     type: ?string,
     *     search_name: ?string,
     *     article: string,
     *     length_mm: ?int,
     *     width_mm: ?int,
     *     thickness_mm: ?int,
     *     thickness: ?float,
     *     user_id: ?int,
     *     visibility: ?string
     * }
     */
    public function canonicalize(array $payload): array
    {
        $searchName = $payload['search_name'] ?? null;
        if (!$searchName && !empty($payload['name'])) {
            $searchName = Material::normalizeSearchName((string) $payload['name']);
        }

        $thickness = $this->normalizeFloat($payload['thickness'] ?? null);

        return [
            'type' => $this->normalizeNullableText($payload['type'] ?? null),
            'search_name' => $this->normalizeNullableText($searchName),
            'article' => $this->normalizeText($payload['article'] ?? null),
            'length_mm' => $this->normalizeInt($payload['length_mm'] ?? null),
            'width_mm' => $this->normalizeInt($payload['width_mm'] ?? null),
            'thickness_mm' => $this->normalizeInt($payload['thickness_mm'] ?? null),
            'thickness' => $thickness,
            'user_id' => array_key_exists('user_id', $payload) ? $this->normalizeInt($payload['user_id']) : null,
            'visibility' => $this->normalizeNullableText($payload['visibility'] ?? Material::VISIBILITY_PRIVATE),
        ];
    }

    private function whereNullable($query, string $column, mixed $value): void
    {
        if ($value === null || $value === '') {
            $query->whereNull($column);
            return;
        }

        $query->where($column, $value);
    }

    private function whereEffectiveThickness($query, ?int $thicknessMm, ?float $thickness): void
    {
        if ($thicknessMm === null && $thickness === null) {
            $query->whereNull('thickness_mm')
                ->whereNull('thickness');
            return;
        }

        $query->where(function ($q) use ($thicknessMm, $thickness) {
            if ($thicknessMm !== null) {
                $q->where('thickness_mm', $thicknessMm);
            } elseif ($this->isWholeNumber($thickness)) {
                $q->where('thickness_mm', (int) $thickness);
            }

            $q->orWhere(function ($fallback) use ($thicknessMm, $thickness) {
                $fallback->whereNull('thickness_mm')
                    ->where('thickness', $this->normalizeFloat($thickness ?? $thicknessMm));
            });
        });
    }

    private function normalizeText(mixed $value): string
    {
        return mb_strtolower(trim((string) ($value ?? '')), 'UTF-8');
    }

    private function normalizeNullableText(mixed $value): ?string
    {
        $normalized = $this->normalizeText($value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) round((float) $value);
    }

    private function normalizeFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 2);
    }

    private function isWholeNumber(?float $value): bool
    {
        return $value !== null && floor($value) === $value;
    }
}
