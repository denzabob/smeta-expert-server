<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Services\LLM\LLMEffectiveCapabilityResolver;
use App\Services\LLM\LLMProfileSmokeService;
use App\Services\LLM\LLMSettingsRepository;
use App\Services\LLM\LLMTaskProfileResolver;
use App\Services\LLM\ProviderRegistry;
use App\Services\LLM\RouterAiModelCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AdminLLMProfileController extends Controller
{
    public function __construct(
        private readonly LLMSettingsRepository $settings,
        private readonly LLMTaskProfileResolver $profiles,
        private readonly RouterAiModelCatalogService $catalog,
        private readonly LLMEffectiveCapabilityResolver $capabilities,
        private readonly LLMProfileSmokeService $smoke,
    ) {}

    public function effective(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json($this->profiles->effective(LLMTaskProfileResolver::EXPERT_CHAT));
    }

    public function save(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'provider' => ['required', 'string', Rule::in(ProviderRegistry::names())],
            'model' => ['required', 'string', 'min:1', 'max:255', 'regex:/^[^\s\x00-\x1F\x7F]+$/u'],
            'enabled' => ['required', 'boolean'],
            'fallback_policy' => ['required', Rule::in(['none', 'global'])],
        ]);
        if (empty($this->settings->getProviderSettings($data['provider'])['api_key'])) {
            throw ValidationException::withMessages(['provider' => 'Провайдер не настроен: отсутствует API key.']);
        }

        $task = LLMTaskProfileResolver::EXPERT_CHAT;
        DB::transaction(function () use ($request, $data, $task): void {
            $old = $this->settings->getTaskProfile($task);
            $this->settings->saveTaskProfile($task, $data);
            AdminAuditLog::create([
                'admin_user_id' => $request->user()->id,
                'target_user_id' => null,
                'action' => 'llm_profile_update',
                'result' => 'success',
                'details' => [
                    'task' => $task,
                    'old' => $old === null ? null : array_intersect_key($old, array_flip(['provider', 'model', 'enabled', 'fallback_policy'])),
                    'new' => $data,
                ],
                'ip_address' => $request->ip(),
            ]);
        });

        return response()->json($this->profiles->effective($task));
    }

    public function catalog(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $query = $request->validate([
            'q' => ['sometimes', 'string', 'max:100'],
            'filter' => ['sometimes', 'array'],
            'filter.*' => [Rule::in(['vision', 'streaming', 'reasoning', 'tools', 'structured_output', 'compatible'])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        return response()->json($this->catalogPage($this->catalog->snapshot(), $query));
    }

    public function refreshCatalog(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json($this->catalogPage($this->catalog->snapshot(true), []));
    }

    public function smoke(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'provider' => ['required', 'string', Rule::in(ProviderRegistry::names())],
            'model' => ['required', 'string', 'min:1', 'max:255', 'regex:/^[^\s\x00-\x1F\x7F]+$/u'],
            'kind' => ['required', Rule::in(['text', 'streaming', 'vision', 'pdf_ocr'])],
        ]);

        return response()->json($this->smoke->run($data['provider'], $data['model'], $data['kind']));
    }

    public function preview(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'provider' => ['required', 'string', Rule::in(ProviderRegistry::names())],
            'model' => ['required', 'string', 'min:1', 'max:255', 'regex:/^[^\s\x00-\x1F\x7F]+$/u'],
        ]);

        return response()->json([
            'capabilities' => $this->capabilities->resolve($data['provider'], $data['model']),
            'model_in_catalog' => $data['provider'] === 'routerai' ? $this->catalog->cachedModel($data['model']) !== null : null,
            'provider_configured' => ! empty($this->settings->getProviderSettings($data['provider'])['api_key']),
        ]);
    }

    private function catalogPage(array $snapshot, array $query): array
    {
        $matches = [];
        $needle = mb_strtolower((string) ($query['q'] ?? ''), 'UTF-8');
        $filters = $query['filter'] ?? [];
        foreach ($snapshot['models'] as $model) {
            if ($needle !== '' && ! str_contains(mb_strtolower($model['id'].' '.($model['display_name'] ?? ''), 'UTF-8'), $needle)) {
                continue;
            }
            $capabilities = $this->capabilities->resolve('routerai', $model['id'], $model);
            $match = true;
            foreach ($filters as $filter) {
                $name = $filter === 'vision' ? 'image_input' : ($filter === 'compatible' ? 'text_input' : $filter);
                if (! ($capabilities[$name] ?? false)) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                $matches[] = [...$model, 'capabilities' => $capabilities];
            }
        }

        $page = (int) ($query['page'] ?? 1);

        return [
            'status' => $snapshot['status'],
            'fetched_at' => $snapshot['fetched_at'],
            'error_code' => $snapshot['error_code'],
            'total' => count($matches),
            'page' => $page,
            'per_page' => 40,
            'models' => array_slice($matches, ($page - 1) * 40, 40),
        ];
    }

    private function authorizeAdmin(Request $request): void
    {
        if (! $request->user() || (int) $request->user()->id !== 1) {
            abort(403, 'Access denied. Admin only.');
        }
    }
}
