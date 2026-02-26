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

        // Collect material and edging operations separately
        $materialOps = [];
        $edgeOps = [];

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
                        $key = $op->id;
                        if (!isset($materialOps[$key])) {
                            $materialOps[$key] = [
                                'operation_id' => $op->id,
                                'name' => $op->name,
                                'category' => $op->category,
                                'unit' => $op->unit,
                                'quantity' => 0.0,
                                'exclusion_group' => $op->exclusion_group ?? null,
                                'source' => 'material',
                            ];
                        }
                        $materialOps[$key]['quantity'] += $qty;
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
                        $key = $op->id;
                        if (!isset($edgeOps[$key])) {
                            $edgeOps[$key] = [
                                'operation_id' => $op->id,
                                'name' => $op->name,
                                'category' => $op->category,
                                'unit' => $op->unit,
                                'quantity' => 0.0,
                                'exclusion_group' => $op->exclusion_group ?? null,
                                'source' => 'edge',
                            ];
                        }
                        $edgeOps[$key]['quantity'] += $qty;
                    }
                }
            }
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

        // Merge all ops: detail first, then material and edge, then manuals
        $merged = [];
        foreach (array_merge($detailOps, $materialOps, $edgeOps) as $entry) {
            $id = $entry['operation_id'];
            if (! isset($merged[$id])) {
                $merged[$id] = $entry;
            } else {
                $merged[$id]['quantity'] += $entry['quantity'];
            }
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

        // Load prices via OperationPriceResolver (batch)
        $operationIds = array_unique(array_column($result, 'operation_id'));
        $pricingContext = $this->resolveOperationPricingContext($project);

        if ($pricingContext['version_id']) {
            $prices = $this->priceResolver->getPricesForVersionBatch($operationIds, $pricingContext['version_id']);
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
            'project_id' => $project->id,
            'supplier_id' => $pricingContext['supplier_id'],
            'price_mode' => $pricingContext['price_mode'],
            'project_operation_version_id' => $pricingContext['version_id'],
            'pricing_resolution' => $pricingContext['resolution'],
            'total_operations' => count($operationIds),
            'found_prices' => $foundCount,
            'not_found' => $notFoundCount,
            'sample_operations' => array_slice($operationIds, 0, 3),
            'sample_prices' => array_slice($prices, 0, 3, true),
        ]);

        // Attach prices and compute total cost per row
        foreach ($result as &$r) {
            $opId = $r['operation_id'];
            $priceData = $prices[$opId] ?? ['price' => 0.0, 'source' => 'missing'];
            
            $r['cost_per_unit'] = (float) $priceData['price'];
            $r['price_source'] = $priceData['source'] ?? 'none';
            $r['price_reason'] = $priceData['reason'] ?? null;
            $r['total_cost'] = round(($r['quantity'] ?? 0) * $r['cost_per_unit'], 2);
        }

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
                'version_id' => (int) $explicit->price_list_version_id,
                'supplier_id' => $explicit->priceListVersion?->priceList?->supplier_id,
                'price_mode' => 'project_version',
                'resolution' => 'explicit_operation_role',
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
                ->select('pl.supplier_id')
                ->first();

            return [
                'version_id' => (int) $fallbackVersionId,
                'supplier_id' => (int) ($versionRow->supplier_id ?? 0) ?: null,
                'price_mode' => 'project_version',
                'resolution' => 'linked_version_with_operation_prices',
            ];
        }

        // 3) Last-resort fallback
        return [
            'version_id' => null,
            'supplier_id' => null,
            'price_mode' => OperationPriceResolver::MODE_MEDIAN,
            'resolution' => 'global_median_fallback',
        ];
    }
}
