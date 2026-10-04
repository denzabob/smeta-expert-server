<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expert\ExpertAiRun;
use App\Models\Expert\ExpertAiRunEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

final class AdminExpertAiRunController extends Controller
{
    private const SAFE_PAYLOAD_INTEGER_KEYS = [
        'material_count',
        'resolved_material_count',
        'active_material_count',
        'duration_ms',
    ];

    private const SAFE_PAYLOAD_CODE_KEYS = [
        'execution_strategy' => 64,
        'requested_mode' => 32,
        'resolved_mode' => 32,
        'error_code' => 100,
        'last_activity_code' => 100,
    ];

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'max:32'],
            'stage' => ['nullable', 'string', 'max:32'],
            'error_code' => ['nullable', 'string', 'max:100'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'project_id' => ['nullable', 'integer', 'min:1'],
            'conversation_id' => ['nullable', 'integer', 'min:1'],
            'run_id' => ['nullable', 'string', 'max:36', 'regex:/^[a-f0-9-]+$/i'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'provider' => ['nullable', 'string', 'max:64'],
            'model' => ['nullable', 'string', 'max:255'],
            'requested_mode' => ['nullable', 'string', 'max:32'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = ExpertAiRun::query()
            ->with([
                'user:id,name',
                'project:id,public_id,name',
                'conversation:id',
            ]);

        foreach (['status', 'stage', 'error_code', 'provider', 'model', 'requested_mode'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $query->where($field, $filters[$field]);
            }
        }
        foreach ([
            'user_id' => 'user_id',
            'project_id' => 'expert_project_id',
            'conversation_id' => 'expert_conversation_id',
        ] as $filter => $column) {
            if (isset($filters[$filter])) {
                $query->where($column, $filters[$filter]);
            }
        }
        if (! empty($filters['run_id'])) {
            $runId = strtolower($filters['run_id']);
            if (strlen($runId) === 36) {
                $query->where('run_id', $runId);
            } else {
                $query->where('run_id', 'like', $runId.'%');
            }
        }
        if (! empty($filters['date_from'])) {
            $query->where('started_at', '>=', Carbon::createFromFormat('!Y-m-d', $filters['date_from']));
        }
        if (! empty($filters['date_to'])) {
            $query->where('started_at', '<', Carbon::createFromFormat('!Y-m-d', $filters['date_to'])->addDay());
        }

        $paginator = $query
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (ExpertAiRun $run): array => $this->runDetails($run))->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, string $runId): JsonResponse
    {
        $this->authorizeAdmin($request);

        $run = ExpertAiRun::query()
            ->with([
                'user:id,name',
                'project:id,public_id,name',
                'conversation:id',
            ])
            ->where('run_id', $runId)
            ->firstOrFail();

        $events = ExpertAiRunEvent::query()
            ->where('expert_ai_run_id', $run->id)
            ->orderBy('seq', 'asc')
            ->get(['seq', 'created_at', 'source', 'level', 'event_code', 'stage', 'status', 'payload'])
            ->map(fn (ExpertAiRunEvent $event): array => [
                'seq' => (int) $event->seq,
                'created_at' => $event->created_at?->toIso8601String(),
                'source' => $event->source,
                'level' => $event->level,
                'event_code' => $event->event_code,
                'stage' => $event->stage,
                'status' => $event->status,
                'payload' => $this->safePayload($event->payload),
            ])->values();

        return response()->json([
            'run' => $this->runDetails($run),
            'events' => $events,
        ]);
    }

    /** @return array<string, mixed> */
    private function runDetails(ExpertAiRun $run): array
    {
        return [
            'run_id' => $run->run_id,
            'started_at' => $run->started_at?->toIso8601String(),
            'first_token_at' => $run->first_token_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'duration_ms' => $run->duration_ms,
            'status' => $run->status,
            'stage' => $run->stage,
            'user' => $run->user ? ['id' => (int) $run->user->id, 'name' => $run->user->name] : null,
            'project' => $run->project ? ['id' => (int) $run->project->id, 'name' => $run->project->name] : null,
            'conversation' => $run->conversation ? ['id' => (int) $run->conversation->id] : null,
            'selected_material_count' => (int) $run->selected_material_count,
            'persisted_material_count' => (int) $run->persisted_material_count,
            'resolved_material_count' => (int) $run->resolved_material_count,
            'active_material_count' => (int) $run->active_material_count,
            'requested_mode' => $run->requested_mode,
            'resolved_mode' => $run->resolved_mode,
            'provider' => $run->provider,
            'model' => $run->model,
            'error_code' => $run->error_code,
            'retryable' => (bool) $run->retryable,
            'finish_reason' => $run->finish_reason,
        ];
    }

    /** @param array<string, mixed>|null $payload @return array<string, bool|int|string>|null */
    private function safePayload(?array $payload): ?array
    {
        if (! is_array($payload)) {
            return null;
        }

        $safe = [];
        foreach (self::SAFE_PAYLOAD_INTEGER_KEYS as $key) {
            if (is_int($payload[$key] ?? null) || (is_string($payload[$key] ?? null) && ctype_digit($payload[$key]))) {
                $safe[$key] = max(0, (int) $payload[$key]);
            }
        }
        foreach (self::SAFE_PAYLOAD_CODE_KEYS as $key => $maxLength) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && preg_match('/^[a-z0-9_.-]{1,'.$maxLength.'}$/i', $value) === 1) {
                $safe[$key] = $value;
            }
        }
        if (is_string($payload['provider'] ?? null) && preg_match('/^[a-z0-9_-]{1,64}$/i', $payload['provider']) === 1) {
            $safe['provider'] = $payload['provider'];
        }
        if (is_string($payload['model'] ?? null) && preg_match('/^[a-z0-9._\/-]{1,255}$/i', $payload['model']) === 1) {
            $safe['model'] = $payload['model'];
        }
        if (is_bool($payload['retryable'] ?? null)) {
            $safe['retryable'] = $payload['retryable'];
        }
        if (is_string($payload['exception_class'] ?? null)
            && preg_match('/^[A-Za-z0-9_\\\\]{1,255}$/', $payload['exception_class']) === 1) {
            $safe['exception_class'] = $payload['exception_class'];
        }

        return $safe === [] ? null : $safe;
    }

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();
        $role = strtolower(trim((string) ($user->role ?? $user->user_role ?? '')));
        $isRoleAdmin = in_array($role, ['admin', 'superadmin'], true);

        if (! $user || (! $isRoleAdmin && (int) $user->id !== 1)) {
            abort(403, 'Access denied. Admin only.');
        }
    }
}
