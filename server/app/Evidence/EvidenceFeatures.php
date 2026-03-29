<?php

namespace App\Evidence;

final class EvidenceFeatures
{
    public static function pipelineV2Enabled(): bool
    {
        return (bool) config('smeta.evidence.pipeline_v2', false);
    }

    public static function facadeEvidenceEnabled(): bool
    {
        return (bool) config('smeta.evidence.facade_enabled', false);
    }

    public static function chromeRevisionEnabled(): bool
    {
        return (bool) config('smeta.evidence.chrome_revision_enabled', false);
    }

    public static function operationsEvidenceEnabled(): bool
    {
        return (bool) config('smeta.evidence.operations_enabled', false);
    }

    public static function laborWorkEvidenceEnabled(): bool
    {
        return (bool) config('smeta.evidence.labor_work_enabled', false);
    }

    public static function expensesEvidenceEnabled(): bool
    {
        return (bool) config('smeta.evidence.expenses_enabled', false);
    }

    public static function expensesDocumentEnabled(): bool
    {
        return (bool) config('smeta.evidence.expenses_document_enabled', false);
    }

    public static function genericChromeEnabled(): bool
    {
        return (bool) config('smeta.evidence.generic_chrome_enabled', false);
    }
}

