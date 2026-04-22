<?php

namespace App\Services;

use App\Models\FinishedProductPriceEvidenceAsset;
use App\Models\FinishedProductPriceSource;
use App\Models\FinishedProductSpecification;
use App\Models\PriceListVersion;
use App\Models\Supplier;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class FinishedProductSpecificationAccessService
{
    public function resolveOwnedFacadeSpecification(
        int $userId,
        int|FinishedProductSpecification $specification
    ): FinishedProductSpecification {
        $resolved = $specification instanceof FinishedProductSpecification
            ? $specification
            : FinishedProductSpecification::query()->findOrFail($specification);

        if ((int) $resolved->user_id !== $userId || $resolved->product_type !== FinishedProductSpecification::TYPE_FACADE) {
            throw new NotFoundHttpException('Finished-product specification not found.');
        }

        return $resolved;
    }

    public function resolveOwnedSource(int $userId, FinishedProductPriceSource $source): FinishedProductPriceSource
    {
        $source->loadMissing('specification:id,user_id,product_type');
        $specification = $source->specification;

        if (!$specification || (int) $specification->user_id !== $userId || $specification->product_type !== FinishedProductSpecification::TYPE_FACADE) {
            throw new NotFoundHttpException('Finished-product price source not found.');
        }

        return $source;
    }

    public function resolveOwnedEvidenceAsset(int $userId, FinishedProductPriceEvidenceAsset $asset): FinishedProductPriceEvidenceAsset
    {
        $asset->loadMissing('source.specification:id,user_id,product_type');
        $specification = $asset->source?->specification;

        if (!$specification || (int) $specification->user_id !== $userId || $specification->product_type !== FinishedProductSpecification::TYPE_FACADE) {
            throw new NotFoundHttpException('Finished-product evidence asset not found.');
        }

        return $asset;
    }

    public function assertOwnedSupplier(?int $supplierId, int $userId): ?Supplier
    {
        if ($supplierId === null) {
            return null;
        }

        $supplier = Supplier::query()
            ->where('id', $supplierId)
            ->where('user_id', $userId)
            ->first();

        if (!$supplier) {
            throw ValidationException::withMessages([
                'supplier_id' => 'Supplier must belong to the current user.',
            ]);
        }

        return $supplier;
    }

    public function assertOwnedPriceListVersion(?int $versionId, int $userId): ?PriceListVersion
    {
        if ($versionId === null) {
            return null;
        }

        $version = PriceListVersion::query()
            ->where('id', $versionId)
            ->whereHas('priceList.supplier', fn ($query) => $query->where('user_id', $userId))
            ->first();

        if (!$version) {
            throw ValidationException::withMessages([
                'price_list_version_id' => 'Price list version must belong to the current user.',
            ]);
        }

        return $version;
    }

    public function assertOwnedLinkedReferences(array $payload, int $userId): void
    {
        $supplier = $this->assertOwnedSupplier(isset($payload['supplier_id']) ? (int) $payload['supplier_id'] : null, $userId);
        $version = $this->assertOwnedPriceListVersion(isset($payload['price_list_version_id']) ? (int) $payload['price_list_version_id'] : null, $userId);

        if ($supplier && $version && (int) $version->priceList->supplier_id !== (int) $supplier->id) {
            throw ValidationException::withMessages([
                'price_list_version_id' => 'Price list version must belong to the selected supplier.',
            ]);
        }
    }
}
