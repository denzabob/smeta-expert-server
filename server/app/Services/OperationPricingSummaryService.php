<?php

namespace App\Services;

use App\Models\Operation;
use App\Models\OperationPrice;
use App\Models\OperationPriceSource;
use App\Models\PriceListVersion;
use App\Models\Project;
use App\Models\ProjectPriceListVersion;
use App\Services\PriceImport\OperationPriceResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OperationPricingSummaryService
{
    public function __construct(
        private readonly OperationPriceResolver $priceResolver,
    ) {}

    public function build(Operation $operation, int $userId, ?Project $project = null): array
    {
        return $this->getSummariesForOperations([$operation->id], $project, $userId)[$operation->id]
            ?? [
                'operation_id' => $operation->id,
                'resolved_source' => null,
                'effective_source' => null,
                'effective_price' => null,
            ];
    }

    /**
     * @param array<int> $operationIds
     * @return array<int, array{
     *     operation_id:int,
     *     resolved_source:array|null,
     *     effective_source:array|null,
     *     effective_price:float|null
     * }>
     */
    public function getSummariesForOperations(array $operationIds, ?Project $project = null, ?int $userId = null): array
    {
        $operationIds = array_values(array_unique(array_filter(array_map('intval', $operationIds), fn (int $id) => $id > 0)));
        if ($operationIds === []) {
            return [];
        }

        $operations = Operation::query()
            ->whereIn('id', $operationIds)
            ->get()
            ->keyBy('id');

        if ($operations->isEmpty()) {
            return [];
        }

        $effectiveUserId = $project?->user_id ? (int) $project->user_id : $userId;

        $activePriceSources = $this->buildActivePriceSourcesBatch($operations);
        $resolvedSources = $this->buildResolvedSourcesBatch($operations, $effectiveUserId, $project);

        $summaries = [];
        foreach ($operationIds as $operationId) {
            $activePriceSource = $activePriceSources[$operationId] ?? null;
            $resolvedSource = $resolvedSources[$operationId] ?? null;

            [$effectiveSource, $effectivePrice] = $this->buildEffectiveSource(
                $activePriceSource,
                $resolvedSource,
            );

            $summaries[$operationId] = [
                'operation_id' => $operationId,
                'resolved_source' => $resolvedSource,
                'effective_source' => $effectiveSource,
                'effective_price' => $effectivePrice,
            ];
        }

        return $summaries;
    }

    private function buildResolvedSource(Operation $operation, int $userId, ?Project $project): ?array
    {
        if ($project) {
            $projectResolved = $this->buildProjectResolvedSource($operation, $project);
            if ($projectResolved) {
                return $projectResolved;
            }
        }

        $latestSupplier = $this->findLatestSupplierContext($operation->id, $userId);
        if ($latestSupplier) {
            $price = $this->priceResolver->getPrice(
                $operation->id,
                OperationPriceResolver::MODE_BY_SUPPLIER,
                $latestSupplier['supplier_id'],
            );

            if ($this->isResolvedPriceFound($price)) {
                return [
                    ...$this->normalizeSource(
                        type: 'resolver',
                        id: 'latest-supplier-version',
                        name: $latestSupplier['name'],
                        price: (float) $price['price'],
                        unit: OperationPrice::normalizeUnit($price['unit'] ?? $operation->unit),
                    ),
                    'resolution' => 'latest_supplier_version',
                ];
            }
        }

        $price = $this->priceResolver->getPrice(
            $operation->id,
            OperationPriceResolver::MODE_MEDIAN,
            null,
        );

        if (!$this->isResolvedPriceFound($price)) {
            return null;
        }

        return [
            ...$this->normalizeSource(
                type: 'resolver',
                id: 'median',
                name: 'Медианная цена',
                price: (float) $price['price'],
                unit: OperationPrice::normalizeUnit($price['unit'] ?? $operation->unit),
            ),
            'resolution' => 'global_median_fallback',
        ];
    }

    /**
     * @param Collection<int, Operation> $operations
     * @return array<int, array|null>
     */
    private function buildResolvedSourcesBatch(Collection $operations, ?int $userId, ?Project $project): array
    {
        $resolvedSources = [];
        $remainingOperationIds = $operations->keys()->map(fn ($id) => (int) $id)->all();

        if ($project) {
            $projectContext = $this->resolveProjectPricingContext($project);
            if ($projectContext) {
                $projectPrices = $this->priceResolver->getPricesForVersionBatchWithRuleContext(
                    $projectContext['version_id'],
                    collect($remainingOperationIds)->mapWithKeys(fn (int $operationId) => [
                        $operationId => [
                            'thickness' => null,
                            'exclusion_group' => null,
                        ],
                    ])->all(),
                );

                foreach ($projectPrices as $operationId => $price) {
                    if (!$this->isResolvedPriceFound($price)) {
                        continue;
                    }

                    $operation = $operations->get($operationId);
                    if (!$operation) {
                        continue;
                    }

                    $resolvedSources[$operationId] = [
                        ...$this->normalizeSource(
                            type: 'resolver',
                            id: 'project-version:' . $projectContext['version_id'],
                            name: $projectContext['name'],
                            price: (float) $price['price'],
                            unit: OperationPrice::normalizeUnit($price['unit'] ?? $operation->unit),
                        ),
                        'resolution' => (string) ($price['source'] ?? $projectContext['resolution']),
                    ];
                }

                $remainingOperationIds = array_values(array_diff($remainingOperationIds, array_keys($resolvedSources)));
            }
        }

        if ($remainingOperationIds !== [] && $userId !== null) {
            $latestSupplierContexts = $this->findLatestSupplierContextsBatch($remainingOperationIds, $userId);
            $operationIdsBySupplier = [];

            foreach ($latestSupplierContexts as $operationId => $context) {
                $operationIdsBySupplier[$context['supplier_id']][] = $operationId;
            }

            foreach ($operationIdsBySupplier as $supplierId => $operationIdsForSupplier) {
                $prices = $this->priceResolver->getPricesBatch(
                    $operationIdsForSupplier,
                    OperationPriceResolver::MODE_BY_SUPPLIER,
                    (int) $supplierId,
                );

                foreach ($operationIdsForSupplier as $operationId) {
                    $price = $prices[$operationId] ?? null;
                    if (!$price || !$this->isResolvedPriceFound($price)) {
                        continue;
                    }

                    $operation = $operations->get($operationId);
                    $context = $latestSupplierContexts[$operationId] ?? null;
                    if (!$operation || !$context) {
                        continue;
                    }

                    $resolvedSources[$operationId] = [
                        ...$this->normalizeSource(
                            type: 'resolver',
                            id: 'latest-supplier-version',
                            name: $context['name'],
                            price: (float) $price['price'],
                            unit: OperationPrice::normalizeUnit($price['unit'] ?? $operation->unit),
                        ),
                        'resolution' => 'latest_supplier_version',
                    ];
                }
            }

            $remainingOperationIds = array_values(array_diff($remainingOperationIds, array_keys($resolvedSources)));
        }

        if ($remainingOperationIds !== []) {
            $medianPrices = $this->priceResolver->getPricesBatch(
                $remainingOperationIds,
                OperationPriceResolver::MODE_MEDIAN,
                null,
            );

            foreach ($remainingOperationIds as $operationId) {
                $price = $medianPrices[$operationId] ?? null;
                if (!$price || !$this->isResolvedPriceFound($price)) {
                    $resolvedSources[$operationId] = null;
                    continue;
                }

                $operation = $operations->get($operationId);
                if (!$operation) {
                    continue;
                }

                $resolvedSources[$operationId] = [
                    ...$this->normalizeSource(
                        type: 'resolver',
                        id: 'median',
                        name: 'Медианная цена',
                        price: (float) $price['price'],
                        unit: OperationPrice::normalizeUnit($price['unit'] ?? $operation->unit),
                    ),
                    'resolution' => 'global_median_fallback',
                ];
            }
        }

        return $resolvedSources;
    }

    private function buildProjectResolvedSource(Operation $operation, Project $project): ?array
    {
        $context = $this->resolveProjectPricingContext($project);
        if (!$context) {
            return null;
        }

        $price = $this->priceResolver
            ->getPricesForVersionBatchWithRuleContext($context['version_id'], [
                $operation->id => [
                    'thickness' => null,
                    'exclusion_group' => null,
                ],
            ])[$operation->id] ?? null;

        if (!$price || !$this->isResolvedPriceFound($price)) {
            return null;
        }

        return [
            ...$this->normalizeSource(
                type: 'resolver',
                id: 'project-version:' . $context['version_id'],
                name: $context['name'],
                price: (float) $price['price'],
                unit: OperationPrice::normalizeUnit($price['unit'] ?? $operation->unit),
            ),
            'resolution' => (string) ($price['source'] ?? $context['resolution']),
        ];
    }

    /**
     * @param Collection<int, Operation> $operations
     * @return array<int, array|null>
     */
    private function buildActivePriceSourcesBatch(Collection $operations): array
    {
        $operationIds = $operations->keys()->map(fn ($id) => (int) $id)->all();
        if ($operationIds === []) {
            return [];
        }

        $sources = OperationPriceSource::query()
            ->active()
            ->whereIn('operation_id', $operationIds)
            ->orderByDesc('id')
            ->get()
            ->unique('operation_id')
            ->keyBy('operation_id');

        $activeSources = [];

        foreach ($operations as $operationId => $operation) {
            /** @var OperationPriceSource|null $source */
            $source = $sources->get($operationId);
            if (!$source) {
                $activeSources[$operationId] = null;
                continue;
            }

            $activeSources[$operationId] = [
                ...$this->normalizeSource(
                    type: $source->type,
                    id: (string) $source->id,
                    name: $source->source_name ?: 'Источник цены',
                    price: $source->value !== null ? (float) $source->value : null,
                    unit: OperationPrice::normalizeUnit($source->unit) ?? OperationPrice::normalizeUnit($operation->unit),
                ),
                'document_ref' => $source->document_ref,
                'is_valid' => $source->value !== null,
                'invalid_reason' => $source->value !== null ? null : 'price_missing',
            ];
        }

        return $activeSources;
    }

    private function buildEffectiveSource(?array $activePriceSource, ?array $resolvedSource): array
    {
        if ($activePriceSource) {
            $effectiveSource = [
                ...$this->stripSourceMetadata($activePriceSource),
                'mode' => 'selected',
            ];

            $effectivePrice = (($activePriceSource['is_valid'] ?? false) === true)
                && isset($activePriceSource['price'])
                && is_numeric($activePriceSource['price'])
                ? (float) $activePriceSource['price']
                : null;

            return [$effectiveSource, $effectivePrice];
        }

        if ($resolvedSource) {
            return [[
                ...$this->stripSourceMetadata($resolvedSource),
                'mode' => 'fallback',
            ], (float) $resolvedSource['price']];
        }

        return [null, null];
    }

    private function normalizeSource(
        string $type,
        string $id,
        string $name,
        ?float $price,
        ?string $unit,
    ): array {
        return [
            'key' => "{$type}:{$id}",
            'type' => $type,
            'id' => $id,
            'name' => $name,
            'price' => $price,
            'unit' => $unit,
        ];
    }

    private function stripSourceMetadata(array $source): array
    {
        return [
            'key' => $source['key'],
            'type' => $source['type'],
            'id' => $source['id'],
            'name' => $source['name'],
            'price' => $source['price'],
            'unit' => $source['unit'],
        ];
    }

    private function isResolvedPriceFound(array $price): bool
    {
        return ($price['source'] ?? 'not_found') !== 'not_found'
            && isset($price['price'])
            && is_numeric($price['price'])
            && (float) $price['price'] > 0;
    }

    private function findLatestSupplierContext(int $operationId, int $userId): ?array
    {
        $row = DB::table('operation_prices as op')
            ->join('price_list_versions as plv', 'plv.id', '=', 'op.price_list_version_id')
            ->join('price_lists as pl', 'pl.id', '=', 'plv.price_list_id')
            ->join('suppliers as s', 's.id', '=', 'pl.supplier_id')
            ->where('op.operation_id', $operationId)
            ->where('plv.status', PriceListVersion::STATUS_ACTIVE)
            ->where('s.user_id', $userId)
            ->orderByDesc('plv.id')
            ->select([
                's.id as supplier_id',
                's.name as supplier_name',
                'plv.id as version_id',
                'pl.name as price_list_name',
                'plv.version_number',
            ])
            ->first();

        if (!$row) {
            return null;
        }

        return [
            'supplier_id' => (int) $row->supplier_id,
            'name' => sprintf(
                'Последняя версия поставщика: %s / %s v%s',
                $row->supplier_name,
                $row->price_list_name,
                $row->version_number,
            ),
            'version_id' => (int) $row->version_id,
        ];
    }

    /**
     * @param array<int> $operationIds
     * @return array<int, array{supplier_id:int,name:string,version_id:int}>
     */
    private function findLatestSupplierContextsBatch(array $operationIds, int $userId): array
    {
        if ($operationIds === []) {
            return [];
        }

        $rows = DB::table('operation_prices as op')
            ->join('price_list_versions as plv', 'plv.id', '=', 'op.price_list_version_id')
            ->join('price_lists as pl', 'pl.id', '=', 'plv.price_list_id')
            ->join('suppliers as s', 's.id', '=', 'pl.supplier_id')
            ->whereIn('op.operation_id', $operationIds)
            ->where('plv.status', PriceListVersion::STATUS_ACTIVE)
            ->where('s.user_id', $userId)
            ->orderBy('op.operation_id')
            ->orderByDesc('plv.id')
            ->select([
                'op.operation_id',
                's.id as supplier_id',
                's.name as supplier_name',
                'plv.id as version_id',
                'pl.name as price_list_name',
                'plv.version_number',
            ])
            ->get();

        $contexts = [];
        foreach ($rows as $row) {
            $operationId = (int) $row->operation_id;
            if (isset($contexts[$operationId])) {
                continue;
            }

            $contexts[$operationId] = [
                'supplier_id' => (int) $row->supplier_id,
                'name' => sprintf(
                    'Последняя версия поставщика: %s / %s v%s',
                    $row->supplier_name,
                    $row->price_list_name,
                    $row->version_number,
                ),
                'version_id' => (int) $row->version_id,
            ];
        }

        return $contexts;
    }

    private function resolveProjectPricingContext(Project $project): ?array
    {
        $explicit = $project->priceListVersionLinks()
            ->where('role', ProjectPriceListVersion::ROLE_OPERATION)
            ->whereHas('priceListVersion', function ($query) {
                $query->where('status', PriceListVersion::STATUS_ACTIVE);
            })
            ->with('priceListVersion.priceList')
            ->orderByDesc('linked_at')
            ->first();

        if ($explicit?->price_list_version_id) {
            $version = $explicit->priceListVersion;

            return [
                'version_id' => (int) $explicit->price_list_version_id,
                'resolution' => 'explicit_operation_role',
                'name' => sprintf(
                    'Версия проекта: %s v%s',
                    $version?->priceList?->name ?? 'Прайс-лист',
                    $version?->version_number ?? '—',
                ),
            ];
        }

        $fallbackVersionId = DB::table('project_price_list_versions as pplv')
            ->join('price_list_versions as plv', 'plv.id', '=', 'pplv.price_list_version_id')
            ->where('pplv.project_id', $project->id)
            ->where('plv.status', PriceListVersion::STATUS_ACTIVE)
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('operation_prices as op')
                    ->whereColumn('op.price_list_version_id', 'pplv.price_list_version_id');
            })
            ->orderByDesc('pplv.linked_at')
            ->value('pplv.price_list_version_id');

        if (!$fallbackVersionId) {
            return null;
        }

        $version = PriceListVersion::query()
            ->with('priceList')
            ->find($fallbackVersionId);

        return [
            'version_id' => (int) $fallbackVersionId,
            'resolution' => 'linked_version_with_operation_prices',
            'name' => sprintf(
                'Связанная версия: %s v%s',
                $version?->priceList?->name ?? 'Прайс-лист',
                $version?->version_number ?? '—',
            ),
        ];
    }
}
