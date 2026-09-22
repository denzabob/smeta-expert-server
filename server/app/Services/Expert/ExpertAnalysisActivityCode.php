<?php

declare(strict_types=1);

namespace App\Services\Expert;

final class ExpertAnalysisActivityCode
{
    public const MATERIALS_PREPARE = 'analysis.materials.prepare';
    public const MATERIAL_STARTED = 'analysis.material.started';
    public const MATERIAL_COMPLETED = 'analysis.material.completed';
    public const MATERIAL_FAILED = 'analysis.material.failed';
    public const COMPARE_STARTED = 'analysis.compare.started';
    public const COMPARE_COMPLETED = 'analysis.compare.completed';
    public const FINAL_STARTED = 'analysis.final.started';
}
