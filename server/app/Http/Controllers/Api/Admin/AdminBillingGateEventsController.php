<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\BillingGateEvent;
use App\Services\Billing\Payments\ProviderPayloadSanitizer;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminBillingGateEventsController extends Controller
{
    public function __construct(
        private ProviderPayloadSanitizer $payloadSanitizer,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $this->validateFilters($request, includePagination: true);
        $paginator = $this->filteredQuery($validated)
            ->with('user:id,name,email')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($validated));

        return response()->json([
            'data' => $paginator->getCollection()
                ->map(fn (BillingGateEvent $event) => $this->eventPayload($event))
                ->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $this->validateFilters($request, includePagination: false);
        $query = $this->filteredQuery($validated);

        return response()->json([
            'total_events' => (clone $query)->count(),
            'would_block_events' => (clone $query)->where('would_block', true)->count(),
            'enforced_events' => (clone $query)->where('enforced', true)->count(),
            'top_capabilities' => (clone $query)
                ->select('capability', DB::raw('COUNT(*) as count'))
                ->groupBy('capability')
                ->orderByDesc('count')
                ->orderBy('capability')
                ->limit(10)
                ->get()
                ->map(fn ($row) => [
                    'capability' => $row->capability,
                    'count' => (int) $row->count,
                ])
                ->values(),
            'top_users' => (clone $query)
                ->leftJoin('users', 'billing_gate_events.user_id', '=', 'users.id')
                ->whereNotNull('billing_gate_events.user_id')
                ->select([
                    'billing_gate_events.user_id',
                    'users.name',
                    'users.email',
                    DB::raw('COUNT(*) as count'),
                ])
                ->groupBy('billing_gate_events.user_id', 'users.name', 'users.email')
                ->orderByDesc('count')
                ->orderBy('billing_gate_events.user_id')
                ->limit(10)
                ->get()
                ->map(fn ($row) => [
                    'user_id' => (int) $row->user_id,
                    'name' => $row->name,
                    'email' => $row->email,
                    'count' => (int) $row->count,
                ])
                ->values(),
        ]);
    }

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();

        if (! $user || ! $user->isAdmin()) {
            abort(403, 'Access denied. Admin only.');
        }
    }

    private function validateFilters(Request $request, bool $includePagination): array
    {
        $rules = [
            'user_id' => 'nullable|integer|min:1',
            'capability' => 'nullable|string|max:100',
            'would_block' => 'nullable|in:true,false,1,0',
            'enforced' => 'nullable|in:true,false,1,0',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
        ];

        if ($includePagination) {
            $rules['per_page'] = 'nullable|integer|min:1|max:100';
            $rules['page'] = 'nullable|integer|min:1';
        }

        return $request->validate($rules);
    }

    private function filteredQuery(array $filters): Builder
    {
        return BillingGateEvent::query()
            ->where(fn (Builder $query) => $query->whereNull('user_id')->orWhere('user_id', '!=', 1))
            ->when(isset($filters['user_id']), fn (Builder $query) => $query->where('user_id', (int) $filters['user_id']))
            ->when(isset($filters['capability']), fn (Builder $query) => $query->where('capability', $filters['capability']))
            ->when(array_key_exists('would_block', $filters), fn (Builder $query) => $query->where('would_block', $this->booleanFilter($filters['would_block'])))
            ->when(array_key_exists('enforced', $filters), fn (Builder $query) => $query->where('enforced', $this->booleanFilter($filters['enforced'])))
            ->when(isset($filters['date_from']), fn (Builder $query) => $query->where('created_at', '>=', Carbon::parse($filters['date_from'])->startOfDay()))
            ->when(isset($filters['date_to']), fn (Builder $query) => $query->where('created_at', '<=', Carbon::parse($filters['date_to'])->endOfDay()));
    }

    private function eventPayload(BillingGateEvent $event): array
    {
        return [
            'id' => $event->id,
            'user_id' => $event->user_id,
            'user' => $event->user ? [
                'id' => $event->user->id,
                'name' => $event->user->name,
                'email' => $event->user->email,
            ] : null,
            'plan_code' => $event->plan_code,
            'capability' => $event->capability,
            'limit_value' => $event->limit_value,
            'usage_value' => $event->usage_value,
            'would_block' => (bool) $event->would_block,
            'enforced' => (bool) $event->enforced,
            'context_json' => $this->payloadSanitizer->sanitize($event->context_json ?? []),
            'created_at' => $event->created_at?->toDateTimeString(),
        ];
    }

    private function booleanFilter(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function perPage(array $filters): int
    {
        return max(1, min((int) ($filters['per_page'] ?? 25), 100));
    }
}
