<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OperationPrice;
use App\Models\Project;
use App\Models\ProjectPosition;
use App\Services\OperationPricingSummaryService;
use App\Services\Smeta\OperationApplicationResolver;
use Illuminate\Support\Collection;

class ProjectsOperationsController extends Controller
{
    public function __construct(
        protected OperationApplicationResolver $operationApplicationResolver,
        protected OperationPricingSummaryService $operationPricingSummaryService
    ) {}

    public function index(Project $project)
    {
        $this->ensureProjectVisible($project);
        $this->authorize('view', $project);

        $positions = $project->positions()->with(['material', 'edgeMaterial', 'detailType'])->get();
        $operationsMap = [];
        $applicationRules = $this->operationApplicationResolver->loadRulesForProject($project);
        $applicationRuleRows = $this->resolveApplicationRows($project, $positions, $applicationRules);

        foreach ($positions as $position) {
            if (!$position->detail_type_id || !$position->detailType) {
                continue;
            }

            $detailType = $position->detailType;
            $dtOperations = $detailType->detailTypeOperations()->with('operation')->get();

            foreach ($dtOperations as $dto) {
                $operation = $dto->operation;
                if (!$operation) {
                    continue;
                }

                $quantity = $this->evaluateFormula($dto->quantity_formula ?? '1', $position) * ($position->quantity ?? 1);
                $key = 'detail_type_' . $operation->id;

                if (!isset($operationsMap[$key])) {
                    $operationsMap[$key] = [
                        'operation_id' => $operation->id,
                        'name' => $operation->name ?? '',
                        'category' => $operation->category ?? '',
                        'unit' => $operation->unit ?? 'шт',
                        'quantity' => 0.0,
                        'source' => 'detail_type',
                        'is_manual' => false,
                        'updated_at' => $operation->updated_at?->toDateTimeString(),
                        'source_url' => $operation->origin === 'user' ? null : 'system',
                    ];
                }

                $operationsMap[$key]['quantity'] += $quantity;
            }
        }

        foreach ($applicationRuleRows as $row) {
            $operation = $row['operation'];
            $key = 'application_rule_' . $row['rule_id'] . '_' . $operation->id;

            if (!isset($operationsMap[$key])) {
                $operationsMap[$key] = [
                    'operation_id' => $operation->id,
                    'name' => $operation->name ?? '',
                    'category' => $operation->category ?? '',
                    'unit' => $row['pricing_unit'] ?? ($operation->unit ?? 'м²'),
                    'quantity' => 0.0,
                    'source' => 'auto',
                    'is_manual' => false,
                    'updated_at' => $operation->updated_at?->toDateTimeString(),
                    'source_url' => $operation->origin === 'user' ? null : 'system',
                    'application_rule_id' => $row['rule_id'],
                    'quantity_source' => $row['quantity_source'],
                    'pricing_unit' => $row['pricing_unit'],
                    'tariff_binding_type' => $row['tariff_binding_type'],
                    'tariff_operation_id' => $row['tariff_operation_id'] ?? $operation->id,
                    'context' => $row['context'] ?? [],
                ];
            }

            $operationsMap[$key]['quantity'] += $row['quantity'];
        }

        $manualOperations = $project->manualOperations()
            ->with('operation')
            ->get();

        foreach ($manualOperations as $manualOp) {
            $operation = $manualOp->operation;
            if (!$operation) {
                continue;
            }

            $quantity = (float) ($manualOp->quantity ?? 0);

            $key = 'manual_' . $manualOp->id;
            if (!isset($operationsMap[$key])) {
                $operationsMap[$key] = [
                    'id' => $manualOp->id,
                    'key' => $key,
                    'project_manual_operation_id' => $manualOp->id,
                    'operation_id' => $operation->id,
                    'name' => $operation->name ?? '',
                    'category' => $operation->category ?? '',
                    'unit' => $operation->unit ?? 'шт',
                    'quantity' => $quantity,
                    'note' => $manualOp->note,
                    'type' => 'manual',
                    'source' => 'manual',
                    'is_manual' => true,
                    'updated_at' => $operation->updated_at?->toDateTimeString(),
                    'source_url' => $operation->origin === 'user' ? null : 'system',
                ];
            } else {
                $operationsMap[$key]['quantity'] += $quantity;
            }
        }

        $operationIds = array_values(array_unique(array_map(
            fn (array $entry) => (int) ($entry['tariff_operation_id'] ?? $entry['operation_id']),
            $operationsMap
        )));
        $pricingSummaries = $this->operationPricingSummaryService->getSummariesForOperations($operationIds, $project);

        $result = [];
        foreach ($operationsMap as $entry) {
            $tariffOperationId = (int) ($entry['tariff_operation_id'] ?? $entry['operation_id']);
            $pricingSummary = $pricingSummaries[$tariffOperationId] ?? null;
            $effectivePrice = $pricingSummary['effective_price'] ?? null;
            $effectiveSource = $pricingSummary['effective_source'] ?? null;
            $pricingUnit = $entry['pricing_unit'] ?? $entry['unit'] ?? null;
            $resolvedPriceUnit = $effectiveSource['unit'] ?? null;
            $unitMismatch = $this->hasUnitMismatch($pricingUnit, $resolvedPriceUnit);
            $isValid = $effectivePrice !== null && !$unitMismatch;
            $price = $effectivePrice !== null ? (float) $effectivePrice : 0.0;
            $amount = $isValid ? round($entry['quantity'] * $price, 2) : null;
            $mode = ($entry['source'] ?? null) === 'auto' ? 'automatic' : 'manual';

            $result[] = array_merge($entry, [
                'mode' => $mode,
                'price' => $price,
                'effective_price' => $effectivePrice !== null ? (float) $effectivePrice : null,
                'effective_source' => $effectiveSource,
                'cost_per_unit' => $price,
                'pricing_unit' => $pricingUnit,
                'resolved_price_unit' => $resolvedPriceUnit,
                'unit_mismatch' => $unitMismatch,
                'is_valid' => $isValid,
                'amount' => $amount,
                'total_cost' => $amount,
            ]);
        }

        return response()->json($result);
    }

    private function ensureProjectVisible(Project $project): void
    {
        if ((int) $project->user_id !== (int) auth()->id()) {
            abort(404);
        }
    }

    private function evaluateFormula(?string $formula, ProjectPosition $position): float
    {
        if (!$formula) {
            return 1.0;
        }

        try {
            if (is_numeric($formula)) {
                return (float) $formula;
            }

            return match (trim($formula)) {
                'perimeter_m' => (($position->width + $position->length) * 2) / 1000.0,
                'area_m2' => (($position->width ?? 0) * ($position->length ?? 0)) / 1_000_000.0,
                default => 1.0,
            };
        } catch (\Exception) {
            return 1.0;
        }
    }

    private function resolveApplicationRows(Project $project, Collection $positions, Collection $rules): array
    {
        if ($rules->isEmpty()) {
            return [];
        }

        $rows = $this->operationApplicationResolver->resolve($project, $positions, $rules);

        return array_values(array_filter(array_map(function (array $row) use ($rules) {
            $rule = $rules->firstWhere('id', $row['rule_id']);
            $operation = $rule?->operation;

            if (!$operation) {
                return null;
            }

            return [
                ...$row,
                'operation' => $operation,
            ];
        }, $rows)));
    }

    private function hasUnitMismatch(?string $pricingUnit, ?string $resolvedPriceUnit): bool
    {
        $expectedUnit = OperationPrice::normalizeUnit($pricingUnit);
        $actualUnit = OperationPrice::normalizeUnit($resolvedPriceUnit);

        return $expectedUnit !== null
            && $actualUnit !== null
            && $expectedUnit !== $actualUnit;
    }
}
