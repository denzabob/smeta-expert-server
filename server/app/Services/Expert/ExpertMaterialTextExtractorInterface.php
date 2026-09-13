<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Models\Expert\ExpertProjectMaterial;

interface ExpertMaterialTextExtractorInterface
{
    public function supports(ExpertProjectMaterial $material): bool;

    /**
     * @throws ExpertMaterialContextException
     */
    public function extract(ExpertProjectMaterial $material, string $contents, string $absolutePath): string;
}
