<?php
// app/Http/Controllers/Api/OperationController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Material;
use App\Models\Operation;
use App\Models\OperationApplicationRule;
use App\Models\OperationPrice;
use App\Models\Project;
use App\Services\OperationAccessService;
use App\Services\OperationPricingSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Illuminate\Validation\ValidationException;

class OperationController extends Controller
{
    public function __construct(
        private readonly OperationAccessService $operationAccessService,
    ) {}

    public function index()
    {
        return Operation::query()
            ->ownOrSystem((int) auth()->id())
            ->withCount([
                'prices as linked_prices_count' => function ($q) {
                    $q->whereNotNull('operation_id');
                }
            ])
            ->get();
    }

    public function getCategories()
    {
        $userId = auth()->id();

        // Получаем уникальные категории: свои + системные/парсинговые
        $categories = Operation::where('user_id', $userId)
            ->orWhere(function ($query) {
                $query->whereNull('user_id')
                    ->whereIn('origin', ['system', 'parser']);
            })
            ->distinct()
            ->pluck('category')
            ->values();

        return response()->json($categories);
    }

    public function store(Request $request)
    {
        $validated = $this->validateOperationPayload($request);

        $operation = DB::transaction(function () use ($validated) {
            $payload = $validated;
            $payload['user_id'] = auth()->id();
            $payload['origin'] = 'user';
            $payload = $this->normalizeOperationPayload($payload);

            $operation = Operation::create($payload);

            $this->syncAutomaticRuleForOperation($operation);
            $this->ensureEnabledAutomaticRuleExists($operation);

            return $operation->fresh();
        });

        return response()->json($operation, 201);
    }

    public function show(Operation $operation)
    {
        $this->operationAccessService->ensureReadable($operation, (int) auth()->id());

        return $operation;
    }

    public function pricingSummary(
        Request $request,
        Operation $operation,
        OperationPricingSummaryService $pricingSummaryService
    ): JsonResponse {
        $userId = (int) $request->user()->id;
        $this->operationAccessService->ensureReadable($operation, $userId);

        $validated = $request->validate([
            'project_id' => 'nullable|integer|min:1|exists:projects,id',
        ]);

        $project = null;
        if (isset($validated['project_id'])) {
            $project = Project::query()->findOrFail($validated['project_id']);
            $this->authorize('view', $project);
        }

        return response()->json(
            $pricingSummaryService->build($operation, (int) $request->user()->id, $project)
        );
    }

    public function applicationRule(Request $request, Operation $operation): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $this->operationAccessService->ensureReadable($operation, $userId);

        $userRule = OperationApplicationRule::query()
            ->where('operation_id', $operation->id)
            ->where('user_id', $userId)
            ->latest('updated_at')
            ->first();

        $systemRule = OperationApplicationRule::query()
            ->where('operation_id', $operation->id)
            ->whereNull('user_id')
            ->where('mode', OperationApplicationRule::MODE_AUTOMATIC)
            ->orderBy('priority')
            ->orderBy('id')
            ->first();

        $activeRule = $this->normalizeLegacyTariffBindingOnLoad($userRule ?? $systemRule, $operation);

        return response()->json([
            'operation_id' => $operation->id,
            'rule' => $this->formatApplicationRule($activeRule, $userId),
        ]);
    }

    public function storeApplicationRule(Request $request, Operation $operation): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $this->operationAccessService->ensureReadable($operation, $userId);

        $validated = $this->validateApplicationRule($request, $operation, $userId);

        $rule = OperationApplicationRule::updateOrCreate(
            [
                'operation_id' => $operation->id,
                'user_id' => $userId,
                'mode' => OperationApplicationRule::MODE_AUTOMATIC,
            ],
            [
                ...$validated,
                'priority' => 1,
            ],
        );

        return response()->json([
            'message' => 'Application rule saved.',
            'rule' => $this->formatApplicationRule($rule, $userId),
        ], 201);
    }

    public function updateApplicationRule(
        Request $request,
        Operation $operation,
        OperationApplicationRule $rule
    ): JsonResponse {
        $userId = (int) $request->user()->id;
        $this->operationAccessService->ensureReadable($operation, $userId);

        if ($rule->operation_id !== $operation->id || $rule->user_id !== $userId) {
            abort(404);
        }

        $rule->update($this->validateApplicationRule($request, $operation, $userId));

        return response()->json([
            'message' => 'Application rule updated.',
            'rule' => $this->formatApplicationRule($rule->fresh(), $userId),
        ]);
    }

    /**
     * Get operation price links (rows from supplier price lists linked to base operation).
     */
    public function priceLinks(Request $request, Operation $operation)
    {
        $userId = auth()->id();
        $this->operationAccessService->ensureReadable($operation, (int) $userId);
        $limit = min(max((int) $request->input('limit', 100), 1), 500);

        $rows = \App\Models\OperationPrice::query()
            ->with([
                'priceListVersion:id,price_list_id,version_number,status',
                'priceListVersion.priceList:id,supplier_id,name',
                'priceListVersion.priceList.supplier:id,user_id,name',
            ])
            ->where('operation_id', $operation->id)
            ->whereHas('priceListVersion.priceList.supplier', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            })
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        $data = $rows->map(function ($row) {
            return [
                'id' => $row->id,
                'operation_id' => $row->operation_id,
                'source_name' => $row->source_name,
                'source_unit' => $row->source_unit,
                'source_price' => $row->source_price,
                'price_per_internal_unit' => $row->price_per_internal_unit,
                'currency' => $row->currency,
                'match_confidence' => $row->match_confidence,
                'supplier_id' => $row->supplier_id,
                'supplier_name' => $row->priceListVersion?->priceList?->supplier?->name,
                'price_list_version_id' => $row->price_list_version_id,
                'price_list_name' => $row->priceListVersion?->priceList?->name,
                'version_number' => $row->priceListVersion?->version_number,
                'version_status' => $row->priceListVersion?->status,
                'updated_at' => $row->updated_at,
            ];
        })->values();

        return response()->json([
            'operation_id' => $operation->id,
            'operation_name' => $operation->name,
            'count' => $data->count(),
            'data' => $data,
        ]);
    }

    public function update(Request $request, Operation $operation)
    {
        $this->operationAccessService->ensureWritable($operation, (int) auth()->id());

        $validated = $this->validateOperationPayload($request);

        $updatedOperation = DB::transaction(function () use ($operation, $validated) {
            $previousKind = $operation->operation_kind;
            $payload = $this->normalizeOperationPayload($validated);

            $operation->update($payload);

            $this->syncAutomaticRuleForOperation($operation->fresh(), $previousKind);
            $this->ensureEnabledAutomaticRuleExists($operation->fresh());

            return $operation->fresh();
        });

        return $updatedOperation;
    }

    // app/Http/Controllers/Api/OperationController.php

    public function destroy(Operation $operation)
    {
        // Запрет удаления системных и парсинговых записей
        if (in_array($operation->origin, ['system', 'parser'])) {
            return response()->json([
                'message' => 'Системные операции нельзя удалять.'
            ], 403);
        }

        // Разрешено удалять только свои
        if ($operation->user_id !== auth()->id()) {
            return response()->json([
                'message' => 'Вы можете удалять только свои операции.'
            ], 403);
        }

        $operation->delete();
        return response()->json(null, 204);
    }

    /**
     * Search operations by name for price import resolution.
     */
    public function search(Request $request)
    {
        $query = $request->input('q', '');
        $limit = min($request->input('limit', 20), 200);

        if (strlen($query) < 2) {
            return response()->json([]);
        }

        $rawLowerQuery = mb_strtolower(trim($query), 'UTF-8');
        $normalizedQuery = Operation::normalizeSearchName($query);
        $searchQuery = '%' . $rawLowerQuery . '%';
        $normalizedSearchQuery = '%' . $normalizedQuery . '%';
        $tokens = array_values(array_filter(preg_split('/\s+/u', $normalizedQuery) ?: [], function ($token) {
            return mb_strlen($token, 'UTF-8') >= 2;
        }));

        $candidateLimit = min(max($limit * 10, 100), 500);

        $candidates = Operation::query()
            ->ownOrSystem((int) auth()->id())
            ->where(function ($q) use ($searchQuery, $normalizedSearchQuery, $tokens) {
                $q->whereRaw('LOWER(name) LIKE ?', [$searchQuery])
                    ->orWhereRaw('LOWER(search_name) LIKE ?', [$normalizedSearchQuery]);

                foreach ($tokens as $token) {
                    $tokenLike = '%' . $token . '%';
                    $q->orWhereRaw('LOWER(name) LIKE ?', [$tokenLike])
                        ->orWhereRaw('LOWER(search_name) LIKE ?', [$tokenLike]);
                }
            })
            ->select(['id', 'name', 'search_name', 'unit', 'category', 'operation_kind', 'exclusion_group'])
            ->limit($candidateLimit)
            ->get();

        $operations = $candidates
            ->map(function ($operation) use ($rawLowerQuery, $normalizedQuery, $tokens) {
                $score = $this->calculateSearchScore(
                    (string) ($operation->name ?? ''),
                    (string) ($operation->search_name ?? ''),
                    $rawLowerQuery,
                    $normalizedQuery,
                    $tokens
                );

                return [
                    'id' => $operation->id,
                    'name' => $operation->name,
                    'unit' => $operation->unit,
                    'category' => $operation->category,
                    'operation_kind' => $operation->operation_kind,
                    'exclusion_group' => $operation->exclusion_group,
                    '_score' => $score,
                ];
            })
            ->sortByDesc('_score')
            ->values()
            ->take($limit)
            ->map(function ($row) {
                unset($row['_score']);
                return $row;
            })
            ->values();

        return response()->json($operations);
    }

    private function calculateSearchScore(string $name, string $searchName, string $rawLowerQuery, string $normalizedQuery, array $tokens): int
    {
        $nameLower = mb_strtolower($name, 'UTF-8');
        $searchLower = mb_strtolower($searchName, 'UTF-8');
        $score = 0;

        if ($nameLower === $rawLowerQuery) {
            $score += 1000;
        }
        if ($searchLower === $normalizedQuery) {
            $score += 900;
        }
        if ($rawLowerQuery !== '' && str_starts_with($nameLower, $rawLowerQuery)) {
            $score += 300;
        }
        if ($normalizedQuery !== '' && str_starts_with($searchLower, $normalizedQuery)) {
            $score += 280;
        }
        if ($rawLowerQuery !== '' && str_contains($nameLower, $rawLowerQuery)) {
            $score += 120;
        }
        if ($normalizedQuery !== '' && str_contains($searchLower, $normalizedQuery)) {
            $score += 100;
        }

        foreach ($tokens as $token) {
            if (str_contains($nameLower, $token)) {
                $score += 35;
            }
            if (str_contains($searchLower, $token)) {
                $score += 30;
            }
        }

        return $score;
    }

    /**
     * Get unique exclusion groups.
     */
    public function getExclusionGroups()
    {
        $userId = auth()->id();

        $groups = Operation::where('user_id', $userId)
            ->orWhere(function ($query) {
                $query->whereNull('user_id')
                    ->whereIn('origin', ['system', 'parser']);
            })
            ->whereNotNull('exclusion_group')
            ->distinct()
            ->pluck('exclusion_group')
            ->values();

        return response()->json($groups);
    }

    private function validateOperationPayload(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:255'],
            'operation_kind' => ['required', 'string', 'in:' . implode(',', Operation::allowedKinds())],
            'exclusion_group' => ['nullable', 'string', 'max:50'],
            'min_thickness' => ['nullable', 'numeric', 'min:0'],
            'max_thickness' => ['nullable', 'numeric', 'min:0'],
            'unit' => ['required', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
        ], [
            'name.required' => 'Укажите название операции.',
            'category.required' => 'Укажите категорию операции.',
            'operation_kind.required' => 'Выберите вид операции.',
            'operation_kind.in' => 'Выбран неверный вид операции.',
            'unit.required' => 'Укажите единицу измерения.',
        ]);
    }

    private function normalizeOperationPayload(array $data): array
    {
        $normalized = Operation::normalizeOperationKindAndExclusionGroup($data);

        try {
            Operation::assertOperationKindAndExclusionGroupConsistency($normalized);
        } catch (InvalidArgumentException $exception) {
            if (($normalized['operation_kind'] ?? null) === Operation::KIND_OTHER
                && Operation::isAutoExclusionGroup($normalized['exclusion_group'] ?? null)
            ) {
                throw ValidationException::withMessages([
                    'exclusion_group' => ['Для operation_kind=other нельзя использовать auto exclusion group.'],
                ]);
            }

            throw ValidationException::withMessages([
                'operation_kind' => ['Некорректная комбинация operation_kind и exclusion_group.'],
            ]);
        }

        return $normalized;
    }

    private function validateApplicationRule(Request $request, Operation $operation, int $userId): array
    {
        $validated = $request->validate([
            'applies_to' => ['required', 'string', 'in:' . implode(',', [
                OperationApplicationRule::APPLIES_TO_MATERIAL_TYPE,
                OperationApplicationRule::APPLIES_TO_MATERIAL_ID,
            ])],
            'material_type' => ['nullable', 'required_if:applies_to,' . OperationApplicationRule::APPLIES_TO_MATERIAL_TYPE, 'string', 'in:plate,edge,facade,hardware'],
            'material_id' => ['nullable', 'required_if:applies_to,' . OperationApplicationRule::APPLIES_TO_MATERIAL_ID, 'integer', 'min:1'],
            'quantity_source' => ['required', 'string', 'in:' . implode(',', [
                ...OperationApplicationRule::supportedQuantitySources(),
            ])],
            'pricing_unit' => ['required', 'string', 'max:40'],
            'quantity_config' => ['nullable', 'array'],
            'quantity_config.multiplier' => ['nullable', 'numeric', 'min:0'],
            'conditions' => ['nullable', 'array'],
            'conditions.thickness' => ['nullable', 'array'],
            'conditions.thickness.min' => ['nullable', 'numeric', 'min:0'],
            'conditions.thickness.max' => ['nullable', 'numeric', 'min:0'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);

        if ($validated['applies_to'] === OperationApplicationRule::APPLIES_TO_MATERIAL_ID && empty($validated['material_id'])) {
            abort(422, 'Material is required.');
        }

        if ($validated['applies_to'] === OperationApplicationRule::APPLIES_TO_MATERIAL_TYPE && empty($validated['material_type'])) {
            abort(422, 'Material type is required.');
        }

        $pricingUnit = OperationPrice::normalizeUnit($validated['pricing_unit']);
        if ($pricingUnit === null) {
            abort(422, 'Pricing unit is required.');
        }

        if (!OperationApplicationRule::isPricingUnitAllowedForOperationKind($operation->operation_kind, $pricingUnit)) {
            throw ValidationException::withMessages([
                'pricing_unit' => ['Неверная единица для этой операции.'],
            ]);
        }

        if (!OperationApplicationRule::isQuantitySourceAllowedForOperationKind($operation->operation_kind, $validated['quantity_source'])) {
            throw ValidationException::withMessages([
                'quantity_source' => ['Неверный способ расчёта для этой операции.'],
            ]);
        }

        $tariffBindingType = OperationApplicationRule::TARIFF_BINDING_OPERATION_RESOLVER;
        $tariffOperationId = (int) $operation->id;

        if (!OperationApplicationRule::isValidTariffBinding($operation, $tariffBindingType, $tariffOperationId)) {
            throw ValidationException::withMessages([
                'tariff_binding' => ['invalid_tariff_binding'],
            ]);
        }

        $material = null;
        if (!empty($validated['material_id'])) {
            $material = Material::query()
                ->whereKey($validated['material_id'])
                ->where(function ($query) use ($userId) {
                    $query->where('origin', 'parser')
                        ->orWhere('user_id', $userId);
                })
                ->first();

            if (!$material) {
                abort(422, 'Selected material is not available.');
            }
        }

        $resolvedMaterialType = $validated['applies_to'] === OperationApplicationRule::APPLIES_TO_MATERIAL_ID
            ? $material?->type
            : $validated['material_type'];

        if ($operation->operation_kind === Operation::KIND_EDGING) {
            if ($resolvedMaterialType !== Material::TYPE_PLATE) {
                throw ValidationException::withMessages([
                    'material_type' => ['Для кромления правило должно применяться к плитной позиции (material_type = plate).'],
                ]);
            }

            if ($validated['quantity_source'] !== OperationApplicationRule::QUANTITY_SOURCE_EDGE_LENGTH) {
                throw ValidationException::withMessages([
                    'quantity_source' => ['Для кромления способ расчёта должен быть "Длина кромки" (edge_length).'],
                ]);
            }

            $thickness = $validated['conditions']['thickness'] ?? null;
            $minProvided = is_array($thickness)
                && array_key_exists('min', $thickness)
                && $thickness['min'] !== null
                && $thickness['min'] !== '';
            $maxProvided = is_array($thickness)
                && array_key_exists('max', $thickness)
                && $thickness['max'] !== null
                && $thickness['max'] !== '';

            if (!$minProvided || !$maxProvided) {
                throw ValidationException::withMessages([
                    'conditions.thickness' => ['Для кромления нужно указать диапазон толщины кромки.'],
                ]);
            }

            $minThickness = (float) $thickness['min'];
            $maxThickness = (float) $thickness['max'];

            if ($minThickness > $maxThickness) {
                throw ValidationException::withMessages([
                    'conditions.thickness' => ['Некорректный диапазон толщины: min должен быть меньше или равен max.'],
                ]);
            }
        }

        return [
            'applies_to' => $validated['applies_to'],
            'material_type' => $validated['applies_to'] === OperationApplicationRule::APPLIES_TO_MATERIAL_ID
                ? $material?->type
                : $validated['material_type'],
            'material_id' => $validated['material_id'] ?? null,
            'quantity_source' => $validated['quantity_source'],
            'pricing_unit' => $pricingUnit,
            'tariff_binding_type' => $tariffBindingType,
            'tariff_operation_id' => $tariffOperationId,
            'tariff_binding_json' => null,
            'conditions_json' => $this->normalizeApplicationRuleConditions($validated['conditions'] ?? null),
            'quantity_config_json' => $this->normalizeApplicationRuleQuantityConfig($validated['quantity_config'] ?? null),
            'is_enabled' => $validated['is_enabled'] ?? true,
        ];
    }

    private function normalizeApplicationRuleConditions(?array $conditions): ?array
    {
        $thickness = $conditions['thickness'] ?? null;
        if (!is_array($thickness)) {
            return null;
        }

        $normalizedThickness = array_filter([
            'min' => isset($thickness['min']) ? (float) $thickness['min'] : null,
            'max' => isset($thickness['max']) ? (float) $thickness['max'] : null,
        ], fn ($value) => $value !== null);

        return $normalizedThickness === []
            ? null
            : ['thickness' => $normalizedThickness];
    }

    private function normalizeApplicationRuleQuantityConfig(?array $quantityConfig): ?array
    {
        if (!is_array($quantityConfig)) {
            return null;
        }

        $normalizedConfig = array_filter([
            'multiplier' => isset($quantityConfig['multiplier']) ? (float) $quantityConfig['multiplier'] : null,
        ], fn ($value) => $value !== null);

        return $normalizedConfig === []
            ? null
            : $normalizedConfig;
    }

    private function syncAutomaticRuleForOperation(Operation $operation, ?string $previousKind = null): void
    {
        if ($operation->user_id === null) {
            return;
        }

        $query = OperationApplicationRule::query()
            ->where('operation_id', $operation->id)
            ->where('user_id', $operation->user_id)
            ->where('mode', OperationApplicationRule::MODE_AUTOMATIC);

        if (!OperationApplicationRule::shouldHaveDefaultAutomaticRule($operation->operation_kind)) {
            if (OperationApplicationRule::shouldHaveDefaultAutomaticRule($previousKind)) {
                (clone $query)->where('is_enabled', true)->update(['is_enabled' => false]);
            }

            return;
        }

        if ((clone $query)->where('is_enabled', true)->exists()) {
            return;
        }

        $existingRule = (clone $query)
            ->latest('updated_at')
            ->first();

        if ($existingRule) {
            $existingRule->update([
                'is_enabled' => true,
                'tariff_binding_type' => OperationApplicationRule::TARIFF_BINDING_OPERATION_RESOLVER,
                'tariff_operation_id' => $operation->id,
                'tariff_binding_json' => null,
            ]);
            return;
        }

        $defaultAttributes = OperationApplicationRule::defaultAutomaticRuleAttributesForOperation($operation);
        if ($defaultAttributes === null) {
            return;
        }

        OperationApplicationRule::create([
            'operation_id' => $operation->id,
            'user_id' => $operation->user_id,
            'priority' => 1,
            ...$defaultAttributes,
        ]);
    }

    private function ensureEnabledAutomaticRuleExists(Operation $operation): void
    {
        if (!OperationApplicationRule::shouldHaveDefaultAutomaticRule($operation->operation_kind)) {
            return;
        }

        $hasEnabledRule = OperationApplicationRule::query()
            ->where('operation_id', $operation->id)
            ->where('user_id', $operation->user_id)
            ->where('mode', OperationApplicationRule::MODE_AUTOMATIC)
            ->where('is_enabled', true)
            ->exists();

        if (!$hasEnabledRule) {
            throw ValidationException::withMessages([
                'operation_kind' => ['Для этой операции нужно активное правило применения.'],
            ]);
        }
    }

    private function formatApplicationRule(?OperationApplicationRule $rule, int $userId): ?array
    {
        if (!$rule) {
            return null;
        }

        return [
            'id' => $rule->id,
            'operation_id' => $rule->operation_id,
            'source' => $rule->user_id === $userId ? 'user' : 'system',
            'is_editable' => $rule->user_id === $userId,
            'mode' => $rule->mode,
            'applies_to' => $rule->applies_to,
            'material_type' => $rule->material_type,
            'material_id' => $rule->material_id,
            'quantity_source' => $rule->quantity_source,
            'pricing_unit' => $rule->pricing_unit,
            'tariff_binding_type' => $rule->tariff_binding_type,
            'tariff_operation_id' => $rule->tariff_operation_id,
            'tariff_binding' => [
                'type' => $rule->tariff_binding_type,
                'operation_id' => $rule->tariff_operation_id,
            ],
            'conditions' => $rule->conditions_json,
            'quantity_config' => $rule->quantity_config_json,
            'priority' => $rule->priority,
            'is_enabled' => (bool) $rule->is_enabled,
            'updated_at' => $rule->updated_at?->toDateTimeString(),
        ];
    }

    private function normalizeLegacyTariffBindingOnLoad(
        ?OperationApplicationRule $rule,
        Operation $operation
    ): ?OperationApplicationRule {
        if (!$rule) {
            return null;
        }

        if (OperationApplicationRule::isValidTariffBinding(
            $operation,
            $rule->tariff_binding_type,
            $rule->tariff_operation_id ? (int) $rule->tariff_operation_id : null
        )) {
            return $rule;
        }

        $rule->forceFill([
            'tariff_binding_type' => OperationApplicationRule::TARIFF_BINDING_OPERATION_RESOLVER,
            'tariff_operation_id' => $operation->id,
            'tariff_binding_json' => null,
        ])->save();

        return $rule->fresh();
    }
}
