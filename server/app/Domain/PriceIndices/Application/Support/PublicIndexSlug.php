<?php

namespace App\Domain\PriceIndices\Application\Support;

final class PublicIndexSlug
{
    public function fromItemCode(string $itemCode): ?string
    {
        $normalized = preg_replace('/[\x{00A0}\x{202F}]/u', ' ', trim($itemCode));
        $normalized = preg_replace('/\s+/u', '', $normalized ?? '');
        if ($normalized === '') {
            return null;
        }

        $normalized = mb_strtolower($normalized, 'UTF-8');
        $normalized = str_replace(['.аг', '.'], ['-ag', '-'], $normalized);

        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $normalized) === 1
            ? $normalized
            : null;
    }
}
