<?php

namespace App\Services\MaterialDimensions;

use App\Models\MaterialDimensionParseFailure;

class FailedDimensionParseCaseLogger
{
    public function log(DimensionParseContext $context, string $reason, array $meta = []): void
    {
        if ($context->normalizedText === '') {
            return;
        }

        $fingerprint = sha1(implode('|', [
            $context->materialType ?? '',
            $context->source ?? '',
            $context->normalizedText,
        ]));

        $existing = MaterialDimensionParseFailure::query()
            ->where('fingerprint', $fingerprint)
            ->first();

        if ($existing) {
            $existing->increment('occurrences');
            $existing->update([
                'last_seen_at' => now(),
                'parse_error_reason' => $reason,
                'last_result' => $meta,
            ]);
            return;
        }

        MaterialDimensionParseFailure::create([
            'fingerprint' => $fingerprint,
            'raw_text' => $context->rawText,
            'normalized_text' => $context->normalizedText,
            'material_type' => $context->materialType,
            'source' => $context->source,
            'parse_error_reason' => $reason,
            'occurrences' => 1,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'last_result' => $meta,
        ]);
    }
}
