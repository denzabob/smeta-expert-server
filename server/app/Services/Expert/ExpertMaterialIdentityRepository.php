<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Models\Expert\ExpertMaterialIdentity;
use App\Models\Expert\ExpertProjectMaterial;

final class ExpertMaterialIdentityRepository
{
    public function findByMaterial(ExpertProjectMaterial $material): ?ExpertMaterialIdentity
    {
        return ExpertMaterialIdentity::where('expert_project_material_id', $material->getKey())->first();
    }

    /** @param array<string, mixed> $values */
    public function save(ExpertProjectMaterial $material, array $values): ExpertMaterialIdentity
    {
        return ExpertMaterialIdentity::updateOrCreate(['expert_project_material_id' => $material->getKey()], $values);
    }

    public function markStale(ExpertProjectMaterial $material): void
    {
        ExpertMaterialIdentity::where('expert_project_material_id', $material->getKey())->update(['state' => 'stale']);
    }

    public function delete(ExpertProjectMaterial $material): void
    {
        ExpertMaterialIdentity::where('expert_project_material_id', $material->getKey())->delete();
    }
}
