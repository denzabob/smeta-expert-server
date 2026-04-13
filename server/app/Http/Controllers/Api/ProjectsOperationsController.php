<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\Request;
use App\Models\Operation;
use App\Models\Material;
use App\Models\ProjectPriceListVersion;
use App\Services\PriceImport\OperationPriceResolver;
use Illuminate\Support\Facades\DB;

class ProjectsOperationsController extends Controller
{
    public function __construct(
        protected OperationPriceResolver $priceResolver
    ) {}

    public function index(Project $project)
    {
        $this->authorize('view', $project);

        $positions = $project->positions;

        // Collect detail-type operations
        $detailOps = [];
        foreach ($positions as $pos) {
            $detailType = $pos->detailType;
            if (! $detailType) continue;

            // 1) operations attached via detail_type_operations pivot
            $dtOps = $detailType->detailTypeOperations()->with('operation')->get();
            foreach ($dtOps as $dto) {
                $operation = $dto->operation;
                if (! $operation) continue;

                $qty = $this->evaluateFormula($dto->quantity_formula, $pos);
                $qty = $qty * ($pos->quantity ?? 1);

                $key = $operation->id;
                if (! isset($detailOps[$key])) {
                    $detailOps[$key] = [
                        'operation_id' => $operation->id,
                        'name' => $operation->name,
                        'category' => $operation->category,
                        'unit' => $operation->unit,
                        'quantity' => 0.0,
                        'exclusion_group' => $operation->exclusion_group ?? null,
                        'source' => 'detail_type',
                    ];
                }
                $detailOps[$key]['quantity'] += $qty;
            }

            // 2) operations embedded in detail type components JSON (legacy format)
            $components = $detailType->components ?? [];
            if (is_array($components)) {
                foreach ($components as $comp) {
                    if (!is_array($comp)) continue;
                    if (($comp['type'] ?? '') !== 'operation') continue;
                    $opId = $comp['id'] ?? null;
                    if (! $opId) continue;
                    $operation = Operation::find($opId);
                    if (! $operation) continue;
                    $compQty = floatval($comp['quantity'] ?? 1) * ($pos->quantity ?? 1);

                    $key = $operation->id;
                    if (! isset($detailOps[$key])) {
                        $detailOps[$key] = [
                            'operation_id' => $operation->id,
                            'name' => $operation->name,
                            'category' => $operation->category,
                            'unit' => $operation->unit,
                            'quantity' => 0.0,
                            'exclusion_group' => $operation->exclusion_group ?? null,
                            'source' => 'detail_type',
                        ];
                    }
                    $detailOps[$key]['quantity'] += $compQty;
                }
            }
        }

        // Collect material and edging operations as pricing sub-buckets.
        // Each sub-bucket is keyed by "{operation_id}:{thickness}" so that contributions
        // from different thickness contexts are kept separate until after pricing.
        // Contributions with the same operation_id AND the same thickness are still
        // accumulated into one bucket (quantities summed).
        // $autoOpsForMerge is a parallel flat list keyed by operation_id for exclusion logic and
        // the final merge — it holds the display fields only, not the pricing context.
        $autoPricingBuckets = []; // key: "{op_id}:{t}", value: {operation_id, thickness, qty, ..display..}
        $materialOpIds      = []; // operation_ids seen for exclusion tracking
        $edgeOpIds          = []; // operation_ids seen for exclusion tracking
        // Map operation_id → display entry (first-seen wins for display fields, merged quantity below)
        $autoDisplayByOpId  = [];

        foreach ($positions as $pos) {
            $posQty = $pos->quantity ?? 1;

            // Material / cutting
            if (!empty($pos->material_id)) {
                $material = Material::find($pos->material_id);
                if ($material) {
                    $thickness = $material->thickness;
                    $waste = $material->waste_factor ?? 1.0;
                    $area_m2 = (($pos->width ?? 0) * ($pos->length ?? 0)) / 1000000.0;
                    $qty = $area_m2 * $waste * $posQty;

                    $query = Operation::where('exclusion_group', 'cutting');
                    if ($thickness !== null) {
                        $query->where(function ($q) use ($thickness) {
                            $q->whereNull('min_thickness')->orWhere('min_thickness', '<=', $thickness);
                        })->where(function ($q) use ($thickness) {
                            $q->whereNull('max_thickness')->orWhere('max_thickness', '>=', $thickness);
                        });
                    }

                    $op = $query->orderByRaw('COALESCE(max_thickness, 9999) - COALESCE(min_thickness, 0) ASC')->first();
                    if ($op) {
                        $opId = $op->id;
                        $t    = $thickness !== null ? (float) $thickness : null;
                        $bucketKey = $opId . ':' . ($t !== null ? $t : 'null');

                        if (!isset($autoPricingBuckets[$bucketKey])) {
                            $autoPricingBuckets[$bucketKey] = [
                                'key'             => $bucketKey,
                                'operation_id'    => $opId,
                                'thickness'       => $t,
                                'exclusion_group' => $op->exclusion_group ?? null,
                                'quantity'        => 0.0,
                                'source'          => 'material',
                            ];
                        }
                        $autoPricingBuckets[$bucketKey]['quantity'] += $qty;

                        // Store display fields (first-seen per operation_id)
                        if (!isset($autoDisplayByOpId[$opId])) {
                            $autoDisplayByOpId[$opId] = [
                                'operation_id'    => $opId,
                                'name'            => $op->name,
                                'category'        => $op->category,
                                'unit'            => $op->unit,
                                'exclusion_group' => $op->exclusion_group ?? null,
                                'source'          => 'material',
                            ];
                        }
                        $materialOpIds[$opId] = true;
                    }
                }
            }

            // Edge / edging
            if (!empty($pos->edge_material_id) && !empty($pos->edge_scheme) && $pos->edge_scheme !== 'none') {
                $edgeMat = Material::find($pos->edge_material_id);
                if ($edgeMat) {
                    $thickness = $edgeMat->thickness;
                    $waste = $edgeMat->waste_factor ?? 1.0;

                    $W = floatval($pos->width ?? 0);
                    $L = floatval($pos->length ?? 0);
                    switch ($pos->edge_scheme) {
                        case 'O': $len_mm = ($W + $L) * 2; break;
                        case '=': $len_mm = $L * 2; break;
                        case '||': $len_mm = $W * 2; break;
                        case 'L': $len_mm = $W + $L; break;
                        case 'П': $len_mm = $L * 2 + $W; break;
                        default: $len_mm = ($W + $L) * 2;
                    }
                    $len_m = $len_mm / 1000.0;
                    $qty = $len_m * $waste * $posQty;

                    $query = Operation::where('exclusion_group', 'edging');
                    if ($thickness !== null) {
                        $query->where(function ($q) use ($thickness) {
                            $q->whereNull('min_thickness')->orWhere('min_thickness', '<=', $thickness);
                        })->where(function ($q) use ($thickness) {
                            $q->whereNull('max_thickness')->orWhere('max_thickness', '>=', $thickness);
                        });
                    }

                    $op = $query->orderByRaw('COALESCE(max_thickness, 9999) - COALESCE(min_thickness, 0) ASC')->first();
                    if ($op) {
                        $opId = $op->id;
                        $t    = $thickness !== null ? (float) $thickness : null;
                        $bucketKey = $opId . ':' . ($t !== null ? $t : 'null');

                        if (!isset($autoPricingBuckets[$bucketKey])) {
                            $autoPricingBuckets[$bucketKey] = [
                                'key'             => $bucketKey,
                                'operation_id'    => $opId,
                                'thickness'       => $t,
                                'exclusion_group' => $op->exclusion_group ?? null,
                                'quantity'        => 0.0,
                                'source'          => 'edge',
                            ];
                        }
                        $autoPricingBuckets[$bucketKey]['quantity'] += $qty;

                        // Store display fields (first-seen per operation_id)
                        if (!isset($autoDisplayByOpId[$opId])) {
                            $autoDisplayByOpId[$opId] = [
                                'operation_id'    => $opId,
                                'name'            => $op->name,
                                'category'        => $op->category,
                                'unit'            => $op->unit,
                                'exclusion_group' => $op->exclusion_group ?? null,
                                'source'          => 'edge',
                            ];
                        }
                        $edgeOpIds[$opId] = true;
                    }
                }
            }
        }

        // Build legacy-compatible materialOps / edgeOps arrays for exclusion logic below.
        // Only exclusion_group is inspected there, so a minimal stub per operation_id is enough.
        $materialOps = [];
        foreach (array_keys($materialOpIds) as $opId) {
            $materialOps[$opId] = $autoDisplayByOpId[$opId];
        }
        $edgeOps = [];
        foreach (array_keys($edgeOpIds) as $opId) {
            $edgeOps[$opId] = $autoDisplayByOpId[$opId];
        }

        // Exclusion logic: if material or edge ops define exclusion_group, remove detail ops with same group
        $excludeGroups = [];
        foreach (array_merge($materialOps, $edgeOps) as $mop) {
            if (!empty($mop['exclusion_group'])) $excludeGroups[] = $mop['exclusion_group'];
        }
        if (!empty($excludeGroups)) {
            foreach ($detailOps as $k => $d) {
                if (!empty($d['exclusion_group']) && in_array($d['exclusion_group'], $excludeGroups)) {
                    unset($detailOps[$k]);
                }
            }
        }

        // Merge all ops: detailOps first (keyed by operation_id), then auto ops from display map.
        // Quantities for detail-type ops are summed by operation_id as before.
        $merged = [];
        foreach ($detailOps as $entry) {
            $id = $entry['operation_id'];
            if (! isset($merged[$id])) {
                $merged[$id] = $entry;
            } else {
                $merged[$id]['quantity'] += $entry['quantity'];
            }
        }
        // Auto (material + edge) display entries: add to merged with quantity = 0 now;
        // actual quantity will be filled from priced bucket aggregation below.
        foreach ($autoDisplayByOpId as $opId => $display) {
            if (!isset($merged[$opId])) {
                $merged[$opId] = array_merge($display, ['quantity' => 0.0]);
            }
            // quantity for auto entries is computed after pricing; do not add here.
        }

        $result = array_values($merged);

        // Manual operations
        $manuals = $project->manualOperations()->with('operation')->get();
        foreach ($manuals as $m) {
            $result[] = [
                'id' => $m->id,
                'key' => 'manual_'.$m->id,
                'project_manual_operation_id' => $m->id,
                'operation_id' => $m->operation_id,
                'name' => $m->operation->name ?? '',
                'category' => $m->operation->category ?? '',
                'unit' => $m->operation->unit ?? '',
                'quantity' => (float) $m->quantity,
                'note' => $m->note,
                'type' => 'manual',
            ];
        }

        // -----------------------------------------------------------------
        // Pricing
        // -----------------------------------------------------------------
        $operationIds   = array_unique(array_column($result, 'operation_id'));
        $pricingContext = $this->resolveOperationPricingContext($project);

        // $prices maps operation_id → priceData for non-auto operations and the
        // non-version fallback path. For auto operations on the version path,
        // pricing is done per sub-bucket and then collapsed.
        $prices           = [];
        $bucketAggs       = []; // populated only in the version-path branch; used in price-attachment loop
        $nonAutoRowPrices = []; // null-thickness prices for detail/manual rows (version path only)

        if ($pricingContext['version_id']) {
            // === Rule-aware pricing per sub-bucket ===
            // Price each sub-bucket independently so mixed-thickness contributions
            // for the same operation_id each get the correct rule row.
            $bucketItems = array_values($autoPricingBuckets);
            $bucketPrices = $this->priceResolver->getPricesForVersionBatchItems(
                $pricingContext['version_id'],
                $bucketItems
            );

            // Collapse sub-bucket prices back to one entry per operation_id.
            // After this loop, $prices[opId] is the properly weighted/collapsed result
            // for all auto-op buckets for that operation_id.
            $bucketAggs = []; // opId → {total_cost, total_qty, source, reason}
            foreach ($bucketItems as $bucket) {
                $opId        = $bucket['operation_id'];
                $bucketKey   = $bucket['key'];
                $bucketQty   = $bucket['quantity'];
                $priceData   = $bucketPrices[$bucketKey] ?? ['price' => 0.0, 'source' => 'missing'];
                $bucketCost  = $bucketQty * (float) $priceData['price'];

                if (!isset($bucketAggs[$opId])) {
                    $bucketAggs[$opId] = [
                        'total_cost'        => 0.0,
                        'total_qty'         => 0.0,
                        'source'            => $priceData['source'] ?? 'none',
                        'reason'            => $priceData['reason'] ?? null,
                        'bucket_count'      => 0,
                        'rule_bucket_count' => 0,
                    ];
                }
                $bucketAggs[$opId]['total_cost']   += $bucketCost;
                $bucketAggs[$opId]['total_qty']    += $bucketQty;
                $bucketAggs[$opId]['bucket_count']++;
                // Prefer the most informative source label (rule match trumps generic)
                if (($priceData['source'] ?? '') === 'project_version_rule') {
                    $bucketAggs[$opId]['source'] = 'project_version_rule';
                    $bucketAggs[$opId]['rule_bucket_count']++;
                }
            }

            // Build $prices map for auto operations (collapsed, weighted cost_per_unit)
            foreach ($bucketAggs as $opId => $agg) {
                $weightedCpu = $agg['total_qty'] > 0
                    ? $agg['total_cost'] / $agg['total_qty']
                    : 0.0;
                $total  = $agg['bucket_count'];
                $ruleN  = $agg['rule_bucket_count'];
                if ($total === 0 || $ruleN === 0) {
                    $matchType = 'fallback_only';
                } elseif ($ruleN === $total) {
                    $matchType = 'full_rule_match';
                } else {
                    $matchType = 'partial_rule_match';
                }
                $prices[$opId] = [
                    'price'  => $weightedCpu,
                    'source' => $agg['source'],
                    'reason' => $agg['reason'],
                    // Aggregate total_cost and total_qty carried forward for $result attachment
                    '_total_cost'           => $agg['total_cost'],
                    '_total_qty'            => $agg['total_qty'],
                    // Additive match-type metadata carried forward for $result attachment
                    '_pricing_match_type'    => $matchType,
                    '_bucket_count'          => $total,
                    '_matched_bucket_count'  => $ruleN,
                    '_fallback_bucket_count' => $total - $ruleN,
                ];
            }

            // For non-auto rows (surviving detail ops + manual ops), fetch null-thickness
            // rule-aware prices keyed by their own operation_id. This is done from the
            // actual row-origin sets rather than the minus-set of auto bucket ids, so that
            // manual or detail rows sharing an operation_id with an auto bucket still receive
            // a correct null-thickness price instead of the auto weighted CPU.
            $manualOpIdsForPricing = $manuals->pluck('operation_id')->filter()->unique()->values()->all();
            $nonAutoIds = array_unique(
                array_merge(array_keys($detailOps), $manualOpIdsForPricing)
            );
            if (!empty($nonAutoIds)) {
                $ruleContexts = [];
                foreach ($nonAutoIds as $opId) {
                    $ruleContexts[$opId] = ['thickness' => null, 'exclusion_group' => null];
                }
                $nonAutoRowPrices = $this->priceResolver->getPricesForVersionBatchWithRuleContext(
                    $pricingContext['version_id'],
                    $ruleContexts
                );
                // Merge into $prices only for operation_ids that have no auto bucket entry,
                // preserving the auto weighted data in $prices for auto rows.
                foreach ($nonAutoRowPrices as $opId => $pd) {
                    if (!isset($prices[$opId])) {
                        $prices[$opId] = $pd;
                    }
                }
            }
        } else {
            $prices = $this->priceResolver->getPricesBatch(
                $operationIds,
                $pricingContext['price_mode'],
                $pricingContext['supplier_id']
            );
        }

        // DEBUG: Log pricing info with detailed reasons
        $notFoundCount = count(array_filter($prices, fn($p) => ($p['source'] ?? '') === 'not_found'));
        $foundCount = count($prices) - $notFoundCount;

        \Log::warning('Operations pricing debug', [
            'project_id'                    => $project->id,
            'supplier_id'                   => $pricingContext['supplier_id'],
            'price_mode'                    => $pricingContext['price_mode'],
            'project_operation_version_id'  => $pricingContext['version_id'],
            'pricing_resolution'            => $pricingContext['resolution'],
            'total_operations'              => count($operationIds),
            'found_prices'                  => $foundCount,
            'not_found'                     => $notFoundCount,
            'auto_pricing_buckets'          => count($autoPricingBuckets),
            'sample_operations'             => array_slice($operationIds, 0, 3),
            'sample_prices'                 => array_slice($prices, 0, 3, true),
        ]);

        // -----------------------------------------------------------------
        // Attach prices and compute total cost per row
        // -----------------------------------------------------------------
        foreach ($result as &$r) {
            $opId = $r['operation_id'];

            // A row is an auto-priced aggregate only when it originates from material/edge
            // auto-detection AND has a corresponding bucket aggregate. Manual rows ('type'='manual',
            // no 'source' field) and detail/non-auto rows ('source'='detail_type') that happen to
            // share an operation_id with an auto bucket are excluded here so their quantities and
            // costs are never overwritten by the auto aggregate data.
            $isAutoOpRow = isset($bucketAggs[$opId])
                && in_array($r['source'] ?? '', ['material', 'edge']);

            if ($isAutoOpRow) {
                $priceData = $prices[$opId] ?? ['price' => 0.0, 'source' => 'missing'];
                // Auto aggregate row: use collapsed quantity and pre-computed total_cost
                $r['quantity']             = $priceData['_total_qty'] ?? 0.0;
                $r['cost_per_unit']        = (float) ($priceData['price'] ?? 0.0);
                $r['price_source']         = $priceData['source'] ?? 'none';
                $r['price_reason']         = $priceData['reason'] ?? null;
                $r['total_cost']           = round($priceData['_total_cost'] ?? 0.0, 2);
                // Additive match-type metadata (version path, auto rows only)
                $r['pricing_match_type']    = $priceData['_pricing_match_type'] ?? null;
                $r['pricing_bucket_count']  = $priceData['_bucket_count'] ?? null;
                $r['matched_bucket_count']  = $priceData['_matched_bucket_count'] ?? null;
                $r['fallback_bucket_count'] = $priceData['_fallback_bucket_count'] ?? null;
            } else {
                // Detail-type and manual rows: prefer their own null-thickness price when
                // available (version path via $nonAutoRowPrices), falling back to the general
                // $prices map (non-version path or op_id not in nonAutoRowPrices).
                $priceData = $nonAutoRowPrices[$opId] ?? $prices[$opId] ?? ['price' => 0.0, 'source' => 'missing'];
                $r['cost_per_unit'] = (float) ($priceData['price'] ?? 0.0);
                $r['price_source']  = $priceData['source'] ?? 'none';
                $r['price_reason']  = $priceData['reason'] ?? null;
                $r['total_cost']    = round(($r['quantity'] ?? 0) * $r['cost_per_unit'], 2);
            }
        }

        // Attach pricing-context IDs so the frontend can link to the version page.
        // These are the same for every row (project-level context); manual rows
        // are also annotated so the UI can decide whether to show a link.
        foreach ($result as &$r) {
            $r['price_list_version_id'] = $pricingContext['version_id'];
            $r['price_list_id']         = $pricingContext['price_list_id'] ?? null;
            $r['supplier_id']           = $pricingContext['supplier_id'] ?? null;
        }
        unset($r);

        return response()->json($result);
    }

    private function evaluateFormula(string $formula, $position)
    {
        // position has width (mm), length (mm), quantity
        switch (trim($formula)) {
            case 'perimeter_m':
                return (($position->width + $position->length) * 2) / 1000.0;
            case 'area_m2':
                return ($position->width * $position->length) / 1000000.0;
            default:
                // numeric literal
                if (is_numeric($formula)) return (float) $formula;
                return 0.0;
        }
    }

    /**
     * Resolve project-specific pricing context for operations.
     *
     * Priority:
     * 1) project_price_list_versions with role=operation_price (active version)
     * 2) any project-linked active version that actually has operation_prices
     * 3) global median
     *
     * @return array{version_id:int|null,supplier_id:int|null,price_mode:string,resolution:string}
     */
    private function resolveOperationPricingContext(Project $project): array
    {
        // 1) Explicit role for operations
        $explicit = $project->priceListVersionLinks()
            ->where('role', ProjectPriceListVersion::ROLE_OPERATION)
            ->whereHas('priceListVersion', function ($q) {
                $q->where('status', 'active');
            })
            ->with('priceListVersion')
            ->orderByDesc('linked_at')
            ->first();

        if ($explicit?->price_list_version_id) {
            return [
                'version_id'    => (int) $explicit->price_list_version_id,
                'price_list_id' => $explicit->priceListVersion?->price_list_id,
                'supplier_id'   => $explicit->priceListVersion?->priceList?->supplier_id,
                'price_mode'    => 'project_version',
                'resolution'    => 'explicit_operation_role',
            ];
        }

        // 2) Backward-compatible fallback: pick latest linked active version with operation prices
        $fallbackVersionId = DB::table('project_price_list_versions as pplv')
            ->join('price_list_versions as plv', 'plv.id', '=', 'pplv.price_list_version_id')
            ->where('pplv.project_id', $project->id)
            ->where('plv.status', 'active')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('operation_prices as op')
                    ->whereColumn('op.price_list_version_id', 'pplv.price_list_version_id');
            })
            ->orderByDesc('pplv.linked_at')
            ->value('pplv.price_list_version_id');

        if ($fallbackVersionId) {
            $versionRow = DB::table('price_list_versions as plv')
                ->join('price_lists as pl', 'pl.id', '=', 'plv.price_list_id')
                ->where('plv.id', $fallbackVersionId)
                ->select('pl.supplier_id', 'plv.price_list_id')
                ->first();

            return [
                'version_id'    => (int) $fallbackVersionId,
                'price_list_id' => $versionRow->price_list_id ?? null,
                'supplier_id'   => (int) ($versionRow->supplier_id ?? 0) ?: null,
                'price_mode'    => 'project_version',
                'resolution'    => 'linked_version_with_operation_prices',
            ];
        }

        // 3) Last-resort fallback
        return [
            'version_id'    => null,
            'price_list_id' => null,
            'supplier_id'   => null,
            'price_mode'    => OperationPriceResolver::MODE_MEDIAN,
            'resolution'    => 'global_median_fallback',
        ];
    }
}
