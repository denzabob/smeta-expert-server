<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\BillingPlan;
use App\Services\Billing\BillingCodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AdminBillingPlansController extends Controller
{
    private const PERIODS = ['month', 'year', 'one_time', 'custom'];

    private const PROTECTED_UPDATE_KEYS = [
        'name',
        'description',
        'features',
        'sort_order',
        'hidden',
    ];

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $plans = BillingPlan::query()
            ->orderBy('code')
            ->get()
            ->sortBy(fn (BillingPlan $plan) => sprintf('%010d:%s', (int) ($plan->metadata_json['sort_order'] ?? 9999), $plan->code))
            ->values()
            ->map(fn (BillingPlan $plan) => $this->planPayload($plan));

        return response()->json([
            'data' => $plans,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $this->validatePlanPayload($request, create: true);
        $metadata = $this->metadataFromValidated($validated);
        $this->validateProtectedMetadata($validated['code'], $metadata, true);
        $this->validatePublicPlanMetadata($validated['code'], $validated['name'], $metadata);

        $plan = BillingPlan::query()->create([
            'code' => $validated['code'],
            'name' => $validated['name'],
            'is_active' => $validated['is_active'] ?? true,
            'metadata_json' => $metadata,
        ]);

        return response()->json([
            'data' => $this->planPayload($plan),
        ], 201);
    }

    public function show(Request $request, BillingPlan $plan): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json([
            'data' => $this->planPayload($plan),
        ]);
    }

    public function update(Request $request, BillingPlan $plan): JsonResponse
    {
        $this->authorizeAdmin($request);

        if ($request->has('code')) {
            abort(422, 'Plan code cannot be changed.');
        }

        $validated = $this->validatePlanPayload($request, create: false);

        if ($this->isProtectedPlan($plan)) {
            $this->validateProtectedUpdateKeys($validated);
        }

        $metadata = $this->metadataFromValidated($validated, $plan->metadata_json ?? []);
        $this->validateProtectedMetadata($plan->code, $metadata, (bool) ($validated['is_active'] ?? $plan->is_active));
        $this->validatePublicPlanMetadata($plan->code, $validated['name'] ?? $plan->name, $metadata);

        $plan->fill([
            'name' => $validated['name'] ?? $plan->name,
            'is_active' => $validated['is_active'] ?? $plan->is_active,
            'metadata_json' => $metadata,
        ]);
        $plan->save();

        return response()->json([
            'data' => $this->planPayload($plan),
        ]);
    }

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();

        if (! $user || ! $user->isAdmin()) {
            abort(403, 'Access denied. Admin only.');
        }
    }

    private function validatePlanPayload(Request $request, bool $create): array
    {
        $payload = $request->all();
        $limitsProvided = array_key_exists('limits', $payload);
        $limits = $this->validateLimitsPayload((array) ($payload['limits'] ?? []));
        unset($payload['limits']);

        $rules = [
            'name' => [$create ? 'required' : 'sometimes', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'price_minor' => [$create ? 'required' : 'sometimes', 'integer', 'min:0'],
            'currency' => [$create ? 'required' : 'sometimes', Rule::in(['RUB'])],
            'billing_period' => [$create ? 'required' : 'sometimes', Rule::in(self::PERIODS)],
            'hidden' => ['sometimes', 'boolean'],
            'sandbox' => ['sometimes', 'boolean'],
            'system' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:2000'],
            'features' => ['sometimes', 'array'],
            'features.*' => ['string', 'max:255'],
        ];

        if ($create) {
            $rules['code'] = ['required', 'string', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('billing_plans', 'code')];
        }

        $validated = Validator::make($payload, $rules)->validate();

        if ($limitsProvided) {
            $validated['limits'] = $limits;
        }

        return $validated;
    }

    private function metadataFromValidated(array $validated, array $existing = []): array
    {
        $metadata = $existing;

        foreach ([
            'price_minor',
            'currency',
            'billing_period',
            'hidden',
            'sandbox',
            'system',
            'sort_order',
            'description',
            'features',
        ] as $key) {
            if (array_key_exists($key, $validated)) {
                $metadata[$key] = $validated[$key];
            }
        }

        $metadata['limits'] = $this->normalizeLimits($validated['limits'] ?? ($metadata['limits'] ?? []));

        return $metadata;
    }

    private function validateLimitsPayload(array $limits): array
    {
        $normalized = [];

        foreach ($limits as $key => $value) {
            if (! in_array($key, BillingCodes::capabilities(), true)) {
                continue;
            }

            if ($value === '' || $value === null) {
                $normalized[$key] = null;
                continue;
            }

            if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
                abort(422, "Invalid limit value for {$key}.");
            }

            $normalized[$key] = (int) $value;
        }

        return $normalized;
    }

    private function normalizeLimits(array $limits): array
    {
        $normalized = [];

        foreach (BillingCodes::capabilities() as $capability) {
            $value = $limits[$capability] ?? null;
            $normalized[$capability] = $value === '' || $value === null ? null : (int) $value;
        }

        return $normalized;
    }

    private function validateProtectedUpdateKeys(array $validated): void
    {
        $blockedKeys = array_values(array_diff(array_keys($validated), self::PROTECTED_UPDATE_KEYS));

        if ($blockedKeys !== []) {
            abort(422, 'System plan is protected from billing, activation, system flag, sandbox, and limits changes.');
        }
    }

    private function validateProtectedMetadata(string $code, array $metadata, bool $isActive): void
    {
        if (! $this->isProtectedPlanData($code, $metadata)) {
            return;
        }

        if (! $isActive) {
            abort(422, 'System plan cannot be made inactive.');
        }

        if ((bool) ($metadata['system'] ?? false) === false) {
            abort(422, 'System plan cannot remove system flag.');
        }

        if ((int) ($metadata['price_minor'] ?? 0) > 0) {
            abort(422, 'System plan cannot be paid.');
        }

        if ($code === 'legacy_unlimited') {
            foreach ($this->normalizeLimits($metadata['limits'] ?? []) as $value) {
                if ($value !== null) {
                    abort(422, 'legacy_unlimited cannot have limited capabilities.');
                }
            }
        }
    }

    private function validatePublicPlanMetadata(string $code, string $name, array $metadata): void
    {
        $isPublicCandidate = ! (bool) ($metadata['hidden'] ?? false)
            && ! (bool) ($metadata['system'] ?? false)
            && ! (bool) ($metadata['sandbox'] ?? false)
            && $code !== 'legacy_unlimited';

        if (! $isPublicCandidate) {
            return;
        }

        if (trim($name) === '') {
            abort(422, 'Public plan must have a name.');
        }

        if (! array_key_exists('price_minor', $metadata) || ! is_numeric($metadata['price_minor'])) {
            abort(422, 'Public plan must have a price.');
        }

        if (($metadata['currency'] ?? null) !== 'RUB') {
            abort(422, 'Public plan must have RUB currency.');
        }

        if (! in_array($metadata['billing_period'] ?? null, self::PERIODS, true)) {
            abort(422, 'Public plan must have a billing period.');
        }
    }

    private function planPayload(BillingPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'code' => $plan->code,
            'name' => $plan->name,
            'is_active' => (bool) $plan->is_active,
            'is_public' => $plan->is_active && ! (bool) (($plan->metadata_json ?? [])['hidden'] ?? false),
            'metadata_json' => $this->normalizedPayloadMetadata($plan),
            'created_at' => $plan->created_at?->toDateTimeString(),
            'updated_at' => $plan->updated_at?->toDateTimeString(),
        ];
    }

    private function normalizedPayloadMetadata(BillingPlan $plan): array
    {
        $metadata = $plan->metadata_json ?? [];
        $metadata['limits'] = $this->normalizeLimits($metadata['limits'] ?? []);
        $metadata['hidden'] = (bool) ($metadata['hidden'] ?? false);
        $metadata['sandbox'] = (bool) ($metadata['sandbox'] ?? false);
        $metadata['system'] = (bool) ($metadata['system'] ?? $plan->code === 'legacy_unlimited');
        $metadata['features'] = array_values($metadata['features'] ?? []);

        return $metadata;
    }

    private function isProtectedPlan(BillingPlan $plan): bool
    {
        return $this->isProtectedPlanData($plan->code, $plan->metadata_json ?? []);
    }

    private function isProtectedPlanData(string $code, array $metadata): bool
    {
        return $code === 'legacy_unlimited' || (bool) ($metadata['system'] ?? false);
    }
}
