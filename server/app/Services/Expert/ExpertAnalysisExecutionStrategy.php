<?php

declare(strict_types=1);

namespace App\Services\Expert;

final class ExpertAnalysisExecutionStrategy
{
    public const DIRECT = 'direct';
    public const RETRIEVAL = 'retrieval';
    public const MULTI_DOCUMENT = 'multi_document';
    public const MULTI_DOCUMENT_EXHAUSTIVE = 'multi_document_exhaustive';

    public static function requiresPipeline(string $strategy): bool
    {
        return in_array($strategy, [self::RETRIEVAL, self::MULTI_DOCUMENT, self::MULTI_DOCUMENT_EXHAUSTIVE], true);
    }
}
