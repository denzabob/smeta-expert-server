<?php

namespace App\Services;

use App\Models\Material;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class FinishedProductPricingBridge
{
    /**
     * The new pricing domain is intentionally bridged to current facade materials only.
     */
    public function resolveFacadeMaterial(int|Material $material): Material
    {
        $resolved = $material instanceof Material ? $material : Material::findOrFail($material);

        if ($resolved->type !== Material::TYPE_FACADE) {
            throw new NotFoundHttpException('Finished-product pricing is currently supported only for facade materials.');
        }

        return $resolved;
    }
}
