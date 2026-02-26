<?php
// app/Http/Controllers/Api/ProjectPositionController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Material;
use App\Models\MaterialPrice;
use App\Models\ProjectPosition;
use App\Models\ProjectPositionPriceQuote;
use App\Models\Project;
use App\Models\PriceListVersion;
use App\Models\ProjectPriceListVersion;
use App\Services\ProjectPositionBulkPolicyService;
use App\Services\PriceAggregationService;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectPositionController extends Controller
{
    public function index(Project $project)
    {
        $this->authorize('view', $project);
        return $project->positions()
            ->with(['material', 'facadeMaterial', 'materialPrice', 'priceQuotes.supplier'])
            ->get();
    }

    /**
     * Common validation rules for position store/update
     */
    private function positionRules(bool $isUpdate = false): array
    {
        $prefix = $isUpdate ? 'sometimes|' : '';

        return [
            'kind' => [($isUpdate ? 'sometimes' : 'nullable'), Rule::in([ProjectPosition::KIND_PANEL, ProjectPosition::KIND_FACADE])],
            'detail_type_id' => 'nullable|exists:detail_types,id',
            'material_id' => 'nullable|exists:materials,id',
            'facade_material_id' => 'nullable|exists:materials,id',
            'material_price_id' => 'nullable|exists:material_prices,id',
            'edge_material_id' => 'nullable|exists:materials,id',
            'edge_scheme' => ['nullable', Rule::in(['none', '=', '||', 'П', 'L', 'O'])],
            'quantity' => $prefix . 'integer|min:1',
            'width' => $prefix . 'numeric|min:0',
            'length' => $prefix . 'numeric|min:0',
            'height' => 'nullable|numeric|min:0',
            'custom_name' => 'nullable|string|max:255',
            'custom_fittings' => 'nullable|json',
            'custom_operations' => 'nullable|json',
            'decor_label' => 'nullable|string|max:255',
            'thickness_mm' => 'nullable|integer|min:1',
            'base_material_label' => 'nullable|string|max:100',
            'finish_type' => ['nullable', Rule::in(ProjectPosition::FINISH_TYPES)],
            'finish_name' => 'nullable|string|max:255',
            'price_per_m2' => 'nullable|numeric|min:0',
            'price_method' => ['nullable', Rule::in(ProjectPosition::PRICE_METHODS)],
            'quote_material_price_ids' => 'nullable|array|max:10',
            'quote_material_price_ids.*' => 'integer|exists:material_prices,id',
            'quote_mismatch_flags' => 'nullable|array',
            'quote_mismatch_flags.*' => 'nullable|array',
            'quote_mismatch_flags.*.*' => 'string|max:64',
        ];
    }

    public function store(Request $request, Project $project)
    {
        $this->authorize('update', $project);
        $validated = $request->validate($this->positionRules());

        $validated['project_id'] = $project->id;
        $validated['kind'] = $validated['kind'] ?? ProjectPosition::KIND_PANEL;

        $priceMethod = $validated['price_method'] ?? ProjectPosition::PRICE_METHOD_SINGLE;
        $quoteIds = $validated['quote_material_price_ids'] ?? [];
        $quoteMismatchFlags = $validated['quote_mismatch_flags'] ?? [];
        unset($validated['quote_material_price_ids'], $validated['quote_mismatch_flags']);

        // Aggregation mode validation
        if ($priceMethod !== ProjectPosition::PRICE_METHOD_SINGLE) {
            if (count($quoteIds) < 2) {
                return response()->json(['message' => 'Aggregation requires at least 2 quotes.'], 422);
            }
            $this->validateQuotesBelongToMaterial($quoteIds, $validated['facade_material_id'] ?? null);
        }

        // If facade with facade_material_id + single mode, auto-fill from material + price
        if (($validated['kind'] ?? '') === ProjectPosition::KIND_FACADE && !empty($validated['facade_material_id'])) {
            if ($priceMethod === ProjectPosition::PRICE_METHOD_SINGLE) {
                $validated = $this->enrichFacadeData($validated);
            } else {
                // Aggregation: enrich material metadata but compute price from quotes
                $validated = $this->enrichFacadeData($validated);
                // Price will be overridden by aggregation below
            }
        }

        $validated['price_method'] = $priceMethod;
        $position = ProjectPosition::create($validated);

        if ($priceMethod !== ProjectPosition::PRICE_METHOD_SINGLE && !empty($quoteIds)) {
            $this->persistAggregatedPrice($position, $quoteIds, $priceMethod, $quoteMismatchFlags);
        }

        $position->load(['material', 'facadeMaterial', 'materialPrice', 'priceQuotes']);

        // Auto-link price_list_version to project
        $this->autoLinkPriceVersion($project, $position);
        $this->autoLinkQuoteVersions($project, $position);

        return response()->json($position, 201);
    }

    public function show(ProjectPosition $projectPosition)
    {
        $projectPosition->load(['material', 'facadeMaterial', 'materialPrice', 'priceQuotes.priceListVersion.priceList']);
        return $projectPosition;
    }

    public function update(HttpRequest $request, ProjectPosition $projectPosition)
    {
        $project = $projectPosition->project;
        $this->authorize('update', $project);

        $validated = $request->validate($this->positionRules(true));

        $priceMethod = $validated['price_method'] ?? $projectPosition->price_method ?? ProjectPosition::PRICE_METHOD_SINGLE;
        $quoteIds = $validated['quote_material_price_ids'] ?? [];
        $quoteMismatchFlags = $validated['quote_mismatch_flags'] ?? [];
        unset($validated['quote_material_price_ids'], $validated['quote_mismatch_flags']);

        // Aggregation mode validation
        if ($priceMethod !== ProjectPosition::PRICE_METHOD_SINGLE && !empty($quoteIds)) {
            if (count($quoteIds) < 2) {
                return response()->json(['message' => 'Aggregation requires at least 2 quotes.'], 422);
            }
            $facadeMaterialId = $validated['facade_material_id'] ?? $projectPosition->facade_material_id;
            $this->validateQuotesBelongToMaterial($quoteIds, $facadeMaterialId);
        }

        // If switching to facade or updating facade_material_id, re-fill
        $newKind = $validated['kind'] ?? $projectPosition->kind;
        if ($newKind === ProjectPosition::KIND_FACADE && !empty($validated['facade_material_id'])) {
            $validated = $this->enrichFacadeData($validated);
        }

        // If switching from facade to panel, clear facade fields + aggregation
        if ($newKind === ProjectPosition::KIND_PANEL && $projectPosition->kind === ProjectPosition::KIND_FACADE) {
            $validated = array_merge($validated, [
                'facade_material_id' => null,
                'material_price_id' => null,
                'decor_label' => null,
                'thickness_mm' => null,
                'base_material_label' => null,
                'finish_type' => null,
                'finish_name' => null,
                'price_per_m2' => null,
                'price_method' => ProjectPosition::PRICE_METHOD_SINGLE,
                'price_sources_count' => null,
                'price_min' => null,
                'price_max' => null,
            ]);
            $projectPosition->priceQuotes()->delete();
        }

        $validated['price_method'] = $priceMethod;
        $projectPosition->update($validated);

        if ($priceMethod !== ProjectPosition::PRICE_METHOD_SINGLE && !empty($quoteIds)) {
            $this->persistAggregatedPrice($projectPosition, $quoteIds, $priceMethod, $quoteMismatchFlags);
        } elseif ($priceMethod !== ProjectPosition::PRICE_METHOD_SINGLE && empty($quoteIds)) {
            // Price method changed to aggregated but no new quotes sent
            // Try existing quotes first, then fall back to all available prices for the material
            $existingQuoteIds = $projectPosition->priceQuotes()->pluck('material_price_id')->toArray();
            if (count($existingQuoteIds) >= 2) {
                $this->persistAggregatedPrice($projectPosition, $existingQuoteIds, $priceMethod);
            } else {
                // No existing quotes — find all available prices for this facade material
                $facadeMaterialId = $projectPosition->facade_material_id;
                if ($facadeMaterialId) {
                    $allPriceIds = MaterialPrice::where('material_id', $facadeMaterialId)
                        ->whereHas('priceListVersion', fn($q) => $q->where('status', PriceListVersion::STATUS_ACTIVE))
                        ->pluck('id')
                        ->toArray();
                    if (count($allPriceIds) >= 2) {
                        $this->persistAggregatedPrice($projectPosition, $allPriceIds, $priceMethod);
                    } else {
                        // Not enough sources — revert to single
                        $projectPosition->update(['price_method' => ProjectPosition::PRICE_METHOD_SINGLE]);
                    }
                }
            }
        } elseif ($priceMethod === ProjectPosition::PRICE_METHOD_SINGLE && $projectPosition->priceQuotes()->exists()) {
            // Switched from aggregated to single — clean up quote rows
            $projectPosition->priceQuotes()->delete();
            $projectPosition->update([
                'price_sources_count' => null,
                'price_min' => null,
                'price_max' => null,
            ]);
        }

        $projectPosition->load(['material', 'facadeMaterial', 'materialPrice', 'priceQuotes.supplier']);

        // Auto-link price_list_version to project
        $this->autoLinkPriceVersion($projectPosition->project, $projectPosition);
        $this->autoLinkQuoteVersions($project, $projectPosition);

        return $projectPosition;
    }

    public function destroy(ProjectPosition $projectPosition)
    {
        $project = $projectPosition->project;
        $this->authorize('update', $project);
        $projectPosition->delete();
        return response()->noContent();
    }

    public function bulk(Request $request, Project $project)
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'action' => ['required', Rule::in(['update', 'delete'])],
            'mode' => ['nullable', Rule::in([ProjectPositionBulkPolicyService::MODE_STRICT, ProjectPositionBulkPolicyService::MODE_PARTIAL])],
            'select_all' => 'nullable|boolean',
            'ids' => 'nullable|array',
            'ids.*' => 'integer',
            'updates' => 'nullable|array',
            'updates.material_id' => 'nullable|exists:materials,id',
            'updates.edge_material_id' => 'nullable|exists:materials,id',
            'updates.edge_scheme' => ['nullable', Rule::in(['none', '=', '||', 'П', 'L', 'O'])],
            'updates.custom_name' => 'nullable|string|max:255',
            'updates.facade_material_id' => 'nullable|exists:materials,id',
            'updates.price_method' => ['nullable', Rule::in(ProjectPosition::PRICE_METHODS)],
            'clear_field' => ['nullable', Rule::in(['material_id', 'edge_material_id', 'edge_scheme', 'custom_name', 'facade_material_id'])],
        ]);

        $query = $project->positions();

        if (!($validated['select_all'] ?? false)) {
            $ids = $validated['ids'] ?? [];
            if (empty($ids)) {
                return response()->json([
                    'message' => 'No positions selected.'
                ], 422);
            }
            $query->whereIn('id', $ids);
        }

        if ($validated['action'] === 'delete') {
            $deleted = $query->delete();
            return response()->json(['success' => true, 'deleted' => $deleted]);
        }

        /** @var ProjectPositionBulkPolicyService $policy */
        $policy = app(ProjectPositionBulkPolicyService::class);
        $mode = $validated['mode'] ?? ProjectPositionBulkPolicyService::MODE_STRICT;
        $updates = $validated['updates'] ?? [];
        $clearField = $validated['clear_field'] ?? null;

        if ($clearField) {
            $updates[$clearField] = null;
        }

        if (empty($updates)) {
            return response()->json([
                'message' => 'No updates provided.'
            ], 422);
        }

        $positions = $query->get();
        $operation = $policy->resolveOperation($validated['action'] ?? null, $updates, $clearField);
        $split = $policy->splitApplicable($positions, $operation);
        $applicable = $split['applicable'];
        $skipped = $split['skipped'];

        if ($mode === ProjectPositionBulkPolicyService::MODE_STRICT && count($skipped) > 0) {
            $sample = collect($skipped)->take(5)->values();
            return response()->json([
                'message' => 'Операция недоступна для части выбранных позиций. Измените выбор или включите режим частичного применения.',
                'mode' => $mode,
                'operation' => $operation,
                'updated' => 0,
                'skipped' => count($skipped),
                'skipped_details' => $sample,
            ], 422);
        }

        if ($applicable->isEmpty()) {
            return response()->json([
                'message' => 'Нет подходящих позиций для выбранной операции.',
                'mode' => $mode,
                'operation' => $operation,
                'updated' => 0,
                'skipped' => count($skipped),
                'skipped_details' => collect($skipped)->take(5)->values(),
            ], 422);
        }

        // Facade material requires per-position enrichment (metadata, price, labels)
        if (isset($updates['facade_material_id']) && $updates['facade_material_id']) {
            $enriched = $this->enrichFacadeData([
                'facade_material_id' => $updates['facade_material_id'],
            ]);
            // Apply price_method if provided
            $priceMethod = $updates['price_method'] ?? ProjectPosition::PRICE_METHOD_SINGLE;
            $enriched['price_method'] = $priceMethod;
            unset($enriched['facade_material_id']); // already set separately below

            // If aggregated price method, find all quotes for this material
            $allQuoteIds = [];
            if ($priceMethod !== ProjectPosition::PRICE_METHOD_SINGLE) {
                $allQuoteIds = MaterialPrice::where('material_id', $updates['facade_material_id'])
                    ->whereHas('priceListVersion', fn($q) => $q->where('status', PriceListVersion::STATUS_ACTIVE))
                    ->pluck('id')
                    ->toArray();
                // Need at least 2 quotes for aggregation
                if (count($allQuoteIds) < 2) {
                    $allQuoteIds = [];
                    $enriched['price_method'] = ProjectPosition::PRICE_METHOD_SINGLE;
                }
            }

            $updated = 0;
            foreach ($applicable as $position) {
                $position->facade_material_id = $updates['facade_material_id'];
                $position->fill($enriched);
                $position->save();

                // Perform price aggregation if there are enough quotes
                if ($priceMethod !== ProjectPosition::PRICE_METHOD_SINGLE && count($allQuoteIds) >= 2) {
                    $this->persistAggregatedPrice($position, $allQuoteIds, $priceMethod);
                } elseif ($priceMethod === ProjectPosition::PRICE_METHOD_SINGLE) {
                    // Clean up any old quotes
                    $position->priceQuotes()->delete();
                    $position->update([
                        'price_sources_count' => null,
                        'price_min' => null,
                        'price_max' => null,
                    ]);
                }

                $this->autoLinkPriceVersion($project, $position);
                $this->autoLinkQuoteVersions($project, $position);
                $updated++;
            }
            return response()->json([
                'success' => true,
                'mode' => $mode,
                'operation' => $operation,
                'updated' => $updated,
                'skipped' => count($skipped),
                'skipped_details' => collect($skipped)->take(5)->values(),
            ]);
        }

        // Clearing facade_material_id — also clear related facade fields
        if ($clearField === 'facade_material_id') {
            $updates['material_price_id'] = null;
            $updates['base_material_label'] = null;
            $updates['thickness_mm'] = null;
            $updates['finish_type'] = null;
            $updates['finish_name'] = null;
            $updates['decor_label'] = null;
            $updates['price_per_m2'] = null;
        }

        $updated = 0;
        foreach ($applicable as $position) {
            $position->fill($updates);
            $position->save();
            $updated++;
        }

        return response()->json([
            'success' => true,
            'mode' => $mode,
            'operation' => $operation,
            'updated' => $updated,
            'skipped' => count($skipped),
            'skipped_details' => collect($skipped)->take(5)->values(),
        ]);
    }

    /**
     * POST /api/projects/{project}/positions/recalculate-prices
     * 
     * Recalculate facade prices from current active price list versions
     */
    public function recalculatePrices(Request $request, Project $project)
    {
        $this->authorize('update', $project);

        $positions = $project->positions()
            ->where('kind', ProjectPosition::KIND_FACADE)
            ->whereNotNull('facade_material_id')
            ->get();

        $updated = 0;
        $errors = [];

        foreach ($positions as $position) {
            $price = MaterialPrice::where('material_id', $position->facade_material_id)
                ->whereHas('priceListVersion', function ($q) {
                    $q->where('status', PriceListVersion::STATUS_ACTIVE);
                })
                ->orderByDesc('id')
                ->first();

            if ($price) {
                $position->material_price_id = $price->id;
                $position->price_per_m2 = $price->price_per_internal_unit;
                $position->recalculate();
                $position->save();
                $updated++;
            } else {
                $errors[] = [
                    'position_id' => $position->id,
                    'facade_material_id' => $position->facade_material_id,
                    'reason' => 'No active price found',
                ];
            }
        }

        return response()->json([
            'updated' => $updated,
            'total' => $positions->count(),
            'errors' => $errors,
        ]);
    }

    /**
     * Enrich validated data with facade material attributes and price
     */
    private function enrichFacadeData(array $validated): array
    {
        $material = Material::where('type', Material::TYPE_FACADE)
            ->find($validated['facade_material_id']);

        if (!$material) {
            return $validated;
        }

        $metadata = $material->metadata ?? [];

        // Handle both metadata formats: nested (base.material, finish.type) and flat (base_material, finish_type)
        $baseMaterial = $metadata['base']['material'] ?? $metadata['base_material'] ?? null;
        $validated['base_material_label'] = $baseMaterial ? mb_strtoupper($baseMaterial) : null;
        $validated['thickness_mm'] = $material->thickness_mm ?? ($metadata['thickness_mm'] ?? null);

        // Resolve finish_type: may be enum value or free-text from manual entry
        $rawFinishType = $metadata['finish']['type'] ?? $metadata['finish_type'] ?? null;
        $rawFinishName = $metadata['finish']['name'] ?? $metadata['finish_name'] ?? null;
        if ($rawFinishType && !in_array($rawFinishType, ProjectPosition::FINISH_TYPES, true)) {
            // Free-text (e.g. "Эмаль") — use as finish_name, set type to null
            $validated['finish_name'] = $rawFinishName ?: $rawFinishType;
            $validated['finish_type'] = null;
        } else {
            $validated['finish_type'] = $rawFinishType;
            $validated['finish_name'] = $rawFinishName;
        }

        // Build decor_label
        $finishLabel = match ($validated['finish_type'] ?? '') {
            'pvc_film' => 'ПВХ',
            'plastic' => 'Пластик',
            'enamel' => 'Эмаль',
            'veneer' => 'Шпон',
            'solid_wood' => 'Массив',
            'aluminum_frame' => 'Алюм. рамка',
            default => $validated['finish_type'] ?? '',
        };
        $validated['decor_label'] = trim("{$finishLabel} " . ($validated['finish_name'] ?? ''));

        // Clear panel edge fields for facade
        $validated['edge_material_id'] = null;
        $validated['edge_scheme'] = 'none';

        // Get price from active version, fall back to material.price_per_unit
        if (empty($validated['price_per_m2'])) {
            $price = MaterialPrice::where('material_id', $material->id)
                ->whereHas('priceListVersion', function ($q) {
                    $q->where('status', PriceListVersion::STATUS_ACTIVE);
                })
                ->orderByDesc('id')
                ->first();

            if ($price) {
                $validated['material_price_id'] = $price->id;
                $validated['price_per_m2'] = $price->price_per_internal_unit;
            } elseif ($material->price_per_unit) {
                $validated['price_per_m2'] = $material->price_per_unit;
            }
        }

        return $validated;
    }

    /**
     * Auto-register the price_list_version used by a position into project_price_list_versions.
     */
    private function autoLinkPriceVersion(Project $project, ProjectPosition $position): void
    {
        if (!$position->material_price_id) {
            return;
        }

        $price = MaterialPrice::find($position->material_price_id);
        if (!$price || !$price->price_list_version_id) {
            return;
        }

        $role = $position->isFacade()
            ? ProjectPriceListVersion::ROLE_FACADE
            : ProjectPriceListVersion::ROLE_MATERIAL;

        ProjectPriceListVersion::link($project->id, $price->price_list_version_id, $role);
    }

    /**
     * Persist aggregated price from selected quotes.
     * Replaces existing quote rows, computes aggregated price, updates position.
     */
    /**
     * Persist aggregated price from selected quotes.
     * Replaces existing quote rows, computes aggregated price, updates position.
     *
     * @param array $quoteMismatchFlags Associative array keyed by material_price_id → string[] of mismatched field names
     */
    private function persistAggregatedPrice(ProjectPosition $position, array $materialPriceIds, string $method, array $quoteMismatchFlags = []): void
    {
        // Hard limit
        if (count($materialPriceIds) > 10) {
            abort(422, 'Maximum 10 quotes allowed for aggregated pricing.');
        }

        $service = app(PriceAggregationService::class);

        // Fetch the selected material_prices
        $prices = MaterialPrice::whereIn('id', $materialPriceIds)
            ->with('priceListVersion')
            ->get();

        $priceValues = $prices->pluck('price_per_internal_unit')
            ->map(fn($v) => (float) $v)
            ->toArray();

        $result = $service->aggregate($priceValues, $method);

        // Replace quote rows (delete old, insert new)
        $position->priceQuotes()->delete();

        $now = now();
        foreach ($prices as $mp) {
            // Resolve mismatch_flags for this quote (keyed by material_price_id)
            $flags = $quoteMismatchFlags[$mp->id] ?? ($quoteMismatchFlags[(string) $mp->id] ?? null);
            // Only store non-empty arrays; strict mode quotes get NULL
            $mismatchFlags = (is_array($flags) && count($flags) > 0) ? $flags : null;

            ProjectPositionPriceQuote::create([
                'project_position_id' => $position->id,
                'material_price_id' => $mp->id,
                'price_list_version_id' => $mp->price_list_version_id,
                'supplier_id' => $mp->supplier_id,
                'price_per_m2_snapshot' => (float) $mp->price_per_internal_unit,
                'captured_at' => $now,
                'mismatch_flags' => $mismatchFlags,
            ]);
        }

        // Update position with aggregated values
        $position->update([
            'price_per_m2' => $result['aggregated'],
            'price_method' => $method,
            'price_sources_count' => $result['count'],
            'price_min' => $result['min'],
            'price_max' => $result['max'],
            'material_price_id' => null, // no single primary in aggregation mode
        ]);

        $position->recalculate();
        $position->save();
    }

    /**
     * Validate that all quote material_price_ids are for facade-type materials.
     * Allows quotes from similar (not necessarily identical) facade materials
     * to support extended matching mode.
     */
    private function validateQuotesBelongToMaterial(array $materialPriceIds, ?int $facadeMaterialId): void
    {
        if (!$facadeMaterialId) {
            abort(422, 'facade_material_id is required for aggregated pricing.');
        }

        // Verify the target material is a facade
        $targetMaterial = Material::where('type', Material::TYPE_FACADE)->find($facadeMaterialId);
        if (!$targetMaterial) {
            abort(422, 'facade_material_id must reference a facade-type material.');
        }

        // All quoted prices must belong to facade-type materials
        $nonFacadeCount = MaterialPrice::whereIn('id', $materialPriceIds)
            ->whereHas('material', function ($q) {
                $q->where('type', '!=', Material::TYPE_FACADE);
            })
            ->count();

        if ($nonFacadeCount > 0) {
            abort(422, 'All quote_material_price_ids must belong to facade-type materials.');
        }

        // Hard limit: max 10 quotes
        if (count($materialPriceIds) > 10) {
            abort(422, 'Maximum 10 quotes allowed for aggregated pricing.');
        }
    }

    /**
     * Auto-link all price_list_versions from position's price quotes to the project.
     */
    private function autoLinkQuoteVersions(Project $project, ProjectPosition $position): void
    {
        if (!$position->isFacade()) {
            return;
        }

        $quotes = $position->priceQuotes()->get();
        foreach ($quotes as $quote) {
            if ($quote->price_list_version_id) {
                ProjectPriceListVersion::link(
                    $project->id,
                    $quote->price_list_version_id,
                    ProjectPriceListVersion::ROLE_FACADE
                );
            }
        }
    }
}
