<?php

namespace App\Services;

use App\Models\Material;
use App\Models\MaterialPrice;
use App\Models\ProjectPosition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Service for managing canonical facade materials.
 * Handles CRUD, search_name generation, auto-naming, and soft-delete logic.
 */
class FacadeService
{
    /**
     * List facades with filters, sorting, and quote counts.
     *
     * @param array $filters Keys: base_type, thickness_mm, covering, cover_type, facade_class,
     *                       collection, is_active, search
     * @param string $sortBy  name|updated_at|last_quote_date
     * @param string $sortDir asc|desc
     * @param int $perPage
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function list(array $filters = [], string $sortBy = 'name', string $sortDir = 'asc', int $perPage = 50)
    {
        $query = Material::where('type', Material::TYPE_FACADE)
            ->withCount('prices as quotes_count')
            ->addSelect([
                'last_quote_date' => MaterialPrice::select(DB::raw('MAX(plv.captured_at)'))
                    ->join('price_list_versions as plv', 'material_prices.price_list_version_id', '=', 'plv.id')
                    ->whereColumn('material_prices.material_id', 'materials.id')
                    ->limit(1),
                'last_quote_price' => MaterialPrice::select('price_per_internal_unit')
                    ->join('price_list_versions as plv', 'material_prices.price_list_version_id', '=', 'plv.id')
                    ->whereColumn('material_prices.material_id', 'materials.id')
                    ->orderByDesc('plv.captured_at')
                    ->limit(1),
            ]);

        // Apply filters
        if (!empty($filters['base_type'])) {
            $query->where('facade_base_type', $filters['base_type']);
        }
        if (!empty($filters['thickness_mm'])) {
            $query->where('facade_thickness_mm', (int) $filters['thickness_mm']);
        }
        if (!empty($filters['covering'])) {
            $query->where('facade_covering', $filters['covering']);
        }
        if (!empty($filters['cover_type'])) {
            $query->where('facade_cover_type', $filters['cover_type']);
        }
        if (!empty($filters['facade_class'])) {
            $query->where('facade_class', $filters['facade_class']);
        }
        if (!empty($filters['collection'])) {
            $query->where('facade_collection', 'LIKE', '%' . $filters['collection'] . '%');
        }
        if (isset($filters['is_active'])) {
            $query->where('is_active', (bool) $filters['is_active']);
        }
        if (!empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $query->where(function (Builder $q) use ($search) {
                $q->where('search_name', 'LIKE', $search)
                  ->orWhere('name', 'LIKE', $search)
                  ->orWhere('article', 'LIKE', $search)
                  ->orWhere('facade_decor_label', 'LIKE', $search);
            });
        }

        // Sorting
        $allowedSorts = ['name', 'updated_at', 'last_quote_date', 'facade_class', 'facade_thickness_mm'];
        if (!in_array($sortBy, $allowedSorts)) {
            $sortBy = 'name';
        }
        $query->orderBy($sortBy, $sortDir === 'desc' ? 'desc' : 'asc');

        return $query->paginate($perPage);
    }

    /**
     * Create a new canonical facade.
     */
    public function create(array $data): Material
    {
        $data['type'] = Material::TYPE_FACADE;
        $data['unit'] = 'м²';
        $data['is_active'] = $data['is_active'] ?? true;

        // Auto-generate name if not provided or if auto_name flag is set
        if (empty($data['name']) || !empty($data['auto_name'])) {
            $data['name'] = $this->generateFacadeName($data);
        }
        unset($data['auto_name']);

        // Sync structured columns to metadata
        $data['metadata'] = $this->buildMetadataFromColumns($data);

        // Generate article from spec key
        if (empty($data['article'])) {
            $data['article'] = 'FACADE:' . Material::buildFacadeSpecKey($this->specFromData($data));
        }

        $material = Material::create($data);
        return $material;
    }

    /**
     * Update an existing canonical facade.
     */
    public function update(Material $material, array $data): Material
    {
        // If structured facade fields changed, regenerate name and metadata
        $facadeFieldsDirty = false;
        $structuredFields = ['facade_base_type', 'facade_thickness_mm', 'facade_covering', 'facade_cover_type', 'facade_class', 'facade_collection', 'facade_decor_label', 'facade_price_group_label'];
        foreach ($structuredFields as $f) {
            if (array_key_exists($f, $data) && $data[$f] !== $material->$f) {
                $facadeFieldsDirty = true;
                break;
            }
        }

        if ($facadeFieldsDirty) {
            $merged = array_merge($material->toArray(), $data);
            $data['metadata'] = $this->buildMetadataFromColumns($merged);

            // Regenerate name if auto_name or name wasn't explicitly provided
            if (empty($data['name']) || !empty($data['auto_name'])) {
                $data['name'] = $this->generateFacadeName($merged);
            }
        }
        unset($data['auto_name']);

        $material->update($data);
        return $material->fresh();
    }

    /**
     * Soft-delete: set is_active=0.
     * Physical deletion forbidden if facade has quotes or is used in projects.
     */
    public function delete(Material $material): array
    {
        $quotesCount = MaterialPrice::where('material_id', $material->id)->count();
        $usedInProjects = ProjectPosition::where('facade_material_id', $material->id)->exists();

        if ($quotesCount > 0 || $usedInProjects) {
            $material->update(['is_active' => false]);
            return ['action' => 'deactivated', 'reason' => 'has_references'];
        }

        $material->delete();
        return ['action' => 'deleted'];
    }

    /**
     * Generate a human-readable name for a facade from its structured fields.
     */
    public function generateFacadeName(array $data): string
    {
        $segments = [];

        $baseLabel = Material::BASE_MATERIAL_LABELS[$data['facade_base_type'] ?? ''] ?? mb_strtoupper($data['facade_base_type'] ?? 'МДФ');
        $segments[] = $baseLabel;

        $thickness = $data['facade_thickness_mm'] ?? $data['thickness_mm'] ?? 16;
        $segments[] = "{$thickness}мм";

        $coveringLabel = Material::FINISH_LABELS[$data['facade_covering'] ?? ''] ?? ($data['facade_covering'] ?? '');
        if ($coveringLabel) {
            $segments[] = $coveringLabel;
        }

        $classLabel = Material::FACADE_CLASS_LABELS[$data['facade_class'] ?? ''] ?? ($data['facade_class'] ?? '');
        if ($classLabel) {
            $segments[] = $classLabel;
        }

        if (!empty($data['facade_collection'])) {
            $segments[] = $data['facade_collection'];
        }

        if (!empty($data['facade_decor_label'])) {
            $segments[] = $data['facade_decor_label'];
        }

        return 'Фасад ' . implode(', ', array_filter($segments));
    }

    /**
     * Build metadata JSON from structured columns for backward compatibility.
     */
    private function buildMetadataFromColumns(array $data): array
    {
        return [
            'base' => ['material' => $data['facade_base_type'] ?? 'mdf'],
            'thickness_mm' => (int) ($data['facade_thickness_mm'] ?? 16),
            'finish' => [
                'type' => $data['facade_covering'] ?? '',
                'name' => Material::FINISH_LABELS[$data['facade_covering'] ?? ''] ?? ($data['facade_covering'] ?? ''),
                'variant' => $data['facade_cover_type'] ?? '',
            ],
            'collection' => $data['facade_collection'] ?? '',
            'decor' => $data['facade_decor_label'] ?? '',
            'price_group' => $data['facade_price_group_label'] ?? '',
        ];
    }

    /**
     * Build spec array from data for key generation.
     */
    private function specFromData(array $data): array
    {
        return [
            'base_material' => $data['facade_base_type'] ?? 'mdf',
            'thickness_mm' => $data['facade_thickness_mm'] ?? 16,
            'finish_type' => $data['facade_covering'] ?? '',
            'collection' => $data['facade_collection'] ?? '',
            'decor' => $data['facade_decor_label'] ?? '',
            'price_group' => $data['facade_price_group_label'] ?? '',
        ];
    }

    /**
     * Find similar facades for strict/extended matching.
     *
     * @param Material $material The reference facade
     * @param string $mode strict|extended
     * @return array Array of quotes with mismatch_flags for extended mode
     */
    public function findSimilarQuotes(Material $material, string $mode = 'strict'): array
    {
        $query = MaterialPrice::query()
            ->join('materials as m', 'material_prices.material_id', '=', 'm.id')
            ->join('price_list_versions as plv', 'material_prices.price_list_version_id', '=', 'plv.id')
            ->leftJoin('price_lists as pl', 'plv.price_list_id', '=', 'pl.id')
            ->leftJoin('suppliers as s', 'pl.supplier_id', '=', 's.id')
            ->where('m.type', Material::TYPE_FACADE)
            ->where('m.is_active', true);

        if ($mode === 'strict') {
            $query->where('m.facade_base_type', $material->facade_base_type)
                  ->where('m.facade_thickness_mm', $material->facade_thickness_mm)
                  ->where('m.facade_covering', $material->facade_covering)
                  ->where('m.facade_cover_type', $material->facade_cover_type)
                  ->where('m.facade_class', $material->facade_class);
        } else {
            // Extended: same base_type + covering, but broader
            $query->where('m.facade_base_type', $material->facade_base_type)
                  ->where('m.facade_covering', $material->facade_covering);
        }

        $results = $query->select([
            'material_prices.*',
            'm.id as canon_material_id',
            'm.name as canon_material_name',
            'm.facade_class as canon_facade_class',
            'm.facade_base_type as canon_base_type',
            'm.facade_thickness_mm as canon_thickness_mm',
            'm.facade_covering as canon_covering',
            'm.facade_cover_type as canon_cover_type',
            'm.facade_collection as canon_collection',
            'plv.captured_at as version_captured_at',
            'plv.effective_date as version_effective_date',
            'plv.source_url as version_source_url',
            'plv.original_filename as version_original_filename',
            'plv.file_path as version_file_path',
            'plv.storage_disk as version_storage_disk',
            'plv.version_number as version_number',
            'pl.name as price_list_name',
            's.id as supplier_id_resolved',
            's.name as supplier_name',
        ])
        ->orderByDesc('plv.captured_at')
        ->limit(50)
        ->get();

        return $results->map(function ($row) use ($material, $mode) {
            $mismatchFlags = [];
            if ($mode === 'extended') {
                if ($row->canon_thickness_mm !== $material->facade_thickness_mm) {
                    $mismatchFlags[] = 'facade_thickness_mm';
                }
                if ($row->canon_cover_type !== $material->facade_cover_type) {
                    $mismatchFlags[] = 'facade_cover_type';
                }
                if ($row->canon_facade_class !== $material->facade_class) {
                    $mismatchFlags[] = 'facade_class';
                }
            }

            return [
                'material_price_id' => $row->id,
                'material_id' => $row->canon_material_id,
                'material_name' => $row->canon_material_name,
                'facade_class' => $row->canon_facade_class,
                'price_per_m2' => (float) $row->price_per_internal_unit,
                'currency' => $row->currency ?? 'RUB',
                'supplier_id' => $row->supplier_id_resolved ?? $row->supplier_id,
                'supplier_name' => $row->supplier_name ?? '—',
                'article' => $row->article,
                'category' => $row->category,
                'description' => $row->description,
                'price_list_name' => $row->price_list_name,
                'version_number' => $row->version_number,
                'captured_at' => $row->version_captured_at,
                'effective_date' => $row->version_effective_date,
                'source_url' => $row->version_source_url,
                'original_filename' => $row->version_original_filename,
                'file_path' => $row->version_file_path,
                'mismatch_flags' => $mismatchFlags,
                'price_list_version_id' => $row->price_list_version_id,
            ];
        })->toArray();
    }

    /**
     * Get filter options for facades list.
     */
    public function getFilterOptions(): array
    {
        return [
            'facade_classes' => collect(Material::FACADE_CLASSES)->map(fn ($v) => [
                'value' => $v,
                'label' => Material::FACADE_CLASS_LABELS[$v] ?? $v,
            ]),
            'finish_types' => collect(Material::FINISH_TYPES)->map(fn ($v) => [
                'value' => $v,
                'label' => Material::FINISH_LABELS[$v] ?? $v,
            ]),
            'base_materials' => collect(Material::BASE_MATERIALS)->map(fn ($v) => [
                'value' => $v,
                'label' => Material::BASE_MATERIAL_LABELS[$v] ?? $v,
            ]),
            'finish_variants' => collect(Material::FINISH_VARIANTS)->map(fn ($v) => [
                'value' => $v,
                'label' => Material::FINISH_VARIANT_LABELS[$v] ?? $v,
            ]),
            'price_groups' => Material::PRICE_GROUPS,
            'thickness_options' => Material::where('type', Material::TYPE_FACADE)
                ->whereNotNull('facade_thickness_mm')
                ->distinct()
                ->pluck('facade_thickness_mm')
                ->sort()
                ->values(),
        ];
    }
}
