<?php

namespace App\Evidence;

final class EvidenceFeatures
{
    public static function pipelineV2Enabled(): bool
    {
        return (bool) config('smeta.evidence.pipeline_v2', false);
    }
}

