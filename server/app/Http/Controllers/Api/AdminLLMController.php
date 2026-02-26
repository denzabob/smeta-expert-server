<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LLM\CircuitBreaker;
use App\Services\LLM\LLMRouter;
use App\Services\LLM\LLMSettingsRepository;
use App\Services\LLM\ProviderRegistry;
use App\Services\LLM\Prompts\DecompositionPromptBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Контроллер для управления LLM настройками (админка)
 * 
 * Доступ: только для user_id = 1 (admin)
 */
class AdminLLMController extends Controller
{
    public function __construct(
        private LLMSettingsRepository $settings,
        private LLMRouter $router,
        private CircuitBreaker $circuitBreaker
    ) {}

    /**
     * Получить список доступных провайдеров
     * 
     * GET /api/admin/llm-providers
     */
    public function providers(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json([
            'providers' => ProviderRegistry::getForUI(),
        ]);
    }

    /**
     * Получить текущие настройки LLM
     * 
     * GET /api/admin/llm-settings
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $settings = $this->settings->getAllForAdmin();

        // Добавляем статусы circuit breaker для каждого провайдера
        $circuitBreaker = [];
        foreach (ProviderRegistry::names() as $name) {
            $circuitBreaker[$name] = $this->circuitBreaker->getStats($name);
        }
        $settings['circuit_breaker'] = $circuitBreaker;

        // Добавляем список провайдеров для UI
        $settings['available_providers'] = ProviderRegistry::getForUI();

        return response()->json($settings);
    }

    /**
     * Обновить настройки LLM
     * 
     * PUT /api/admin/llm-settings
     */
    public function update(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        // Динамически строим правила валидации на основе реестра провайдеров
        $providerNames = ProviderRegistry::names();
        $providerInRule = 'in:' . implode(',', $providerNames);

        $rules = [
            'mode' => 'sometimes|in:manual,auto',
            'primary_provider' => 'sometimes|' . $providerInRule,
            'fallback_providers' => 'sometimes|array',
            'fallback_providers.*' => $providerInRule,
            'providers' => 'sometimes|array',
        ];

        // Добавляем правила для каждого провайдера
        foreach ($providerNames as $name) {
            $rules["providers.{$name}"] = 'sometimes|array';
            $rules["providers.{$name}.api_key"] = 'sometimes|nullable|string';
            $rules["providers.{$name}.base_url"] = 'sometimes|nullable|string';
            $rules["providers.{$name}.model"] = 'sometimes|nullable|string';
        }

        $validated = $request->validate($rules);

        $this->settings->saveFromAdmin($validated);

        Log::info('LLM settings updated by admin', [
            'user_id' => $request->user()->id,
            'changes' => array_keys($validated),
        ]);

        return response()->json([
            'message' => 'Settings updated successfully',
            'settings' => $this->settings->getAllForAdmin(),
        ]);
    }

    /**
     * Тестировать провайдеров LLM
     * 
     * POST /api/admin/llm-test
     */
    public function test(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $providerNames = ProviderRegistry::names();

        $validated = $request->validate([
            'providers' => 'sometimes|array',
            'providers.*' => 'in:' . implode(',', $providerNames),
        ]);

        $providersToTest = $validated['providers'] ?? $providerNames;
        $results = [];

        foreach ($providersToTest as $provider) {
            $results[$provider] = $this->router->testProvider($provider);
        }

        return response()->json([
            'results' => $results,
            'tested_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Сбросить circuit breaker для провайдера
     * 
     * POST /api/admin/llm-reset-circuit
     */
    public function resetCircuit(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'provider' => 'required|in:' . implode(',', ProviderRegistry::names()),
        ]);

        $this->router->resetCircuitBreaker($validated['provider']);

        Log::info('Circuit breaker reset by admin', [
            'user_id' => $request->user()->id,
            'provider' => $validated['provider'],
        ]);

        return response()->json([
            'message' => "Circuit breaker for {$validated['provider']} has been reset",
            'circuit_breaker' => $this->circuitBreaker->getStats($validated['provider']),
        ]);
    }

    // =====================================================
    // PROMPTS MANAGEMENT
    // =====================================================

    /**
     * Получить текущие настройки промптов
     * 
     * GET /api/admin/llm-prompts
     */
    public function getPrompts(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        try {
            $settings = DecompositionPromptBuilder::getCurrentSettings();

            return response()->json([
                'success' => true,
                'prompts' => $settings,
                'available_variables' => [
                    '{title}' => 'Название позиции сметы',
                    '{context}' => 'Контекст (родительские элементы)',
                    '{desired_hours}' => 'Желаемое количество часов',
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get prompts', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения настроек промптов',
            ], 500);
        }
    }

    /**
     * Сохранить настройки промптов
     * 
     * PUT /api/admin/llm-prompts
     */
    public function savePrompts(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'system_prompt' => 'required|string|min:50|max:10000',
            'user_prompt_template' => 'required|string|min:20|max:5000',
        ]);

        // Проверяем наличие обязательных переменных в шаблоне
        $requiredVars = ['{title}'];
        $missingVars = [];

        foreach ($requiredVars as $var) {
            if (strpos($validated['user_prompt_template'], $var) === false) {
                $missingVars[] = $var;
            }
        }

        if (!empty($missingVars)) {
            return response()->json([
                'success' => false,
                'message' => 'В шаблоне пользовательского промпта отсутствуют обязательные переменные: ' . implode(', ', $missingVars),
            ], 422);
        }

        try {
            DecompositionPromptBuilder::saveSettings($validated);

            Log::info('LLM prompts updated by admin', [
                'user_id' => $request->user()->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Настройки промптов сохранены',
                'prompts' => DecompositionPromptBuilder::getCurrentSettings(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to save prompts', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка сохранения настроек промптов: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Сбросить промпты к значениям по умолчанию
     * 
     * POST /api/admin/llm-prompts/reset
     */
    public function resetPrompts(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        try {
            DecompositionPromptBuilder::clearCache();

            // Сбрасываем настройки к дефолтным
            DecompositionPromptBuilder::saveSettings([
                'system_prompt' => DecompositionPromptBuilder::DEFAULT_SYSTEM_PROMPT,
                'user_prompt_template' => DecompositionPromptBuilder::DEFAULT_USER_PROMPT_TEMPLATE,
            ]);

            Log::info('LLM prompts reset to defaults by admin', [
                'user_id' => $request->user()->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Промпты сброшены к значениям по умолчанию',
                'prompts' => DecompositionPromptBuilder::getCurrentSettings(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to reset prompts', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка сброса промптов: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Предпросмотр промпта с примерными данными
     * 
     * POST /api/admin/llm-prompts/preview
     */
    public function previewPrompt(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'user_prompt_template' => 'required|string',
            'title' => 'nullable|string',
            'context' => 'nullable|string',
            'desired_hours' => 'nullable|numeric|min:0.1|max:1000',
        ]);

        // Используем примерные данные если не переданы
        $title = $validated['title'] ?? 'Монтаж электропроводки в офисном помещении';
        $context = $validated['context'] ?? 'Раздел: Электромонтажные работы > Подраздел: Внутренние сети';
        $desiredHours = $validated['desired_hours'] ?? 16;

        $template = $validated['user_prompt_template'];

        // Рендерим шаблон
        $rendered = str_replace(
            ['{title}', '{context}', '{desired_hours}'],
            [$title, $context, (string) $desiredHours],
            $template
        );

        return response()->json([
            'success' => true,
            'preview' => $rendered,
            'variables_used' => [
                'title' => $title,
                'context' => $context,
                'desired_hours' => $desiredHours,
            ],
        ]);
    }

    /**
     * Проверить доступ администратора (user_id = 1)
     */
    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();

        if (!$user || (int) $user->id !== 1) {
            abort(403, 'Access denied. Admin only.');
        }
    }
}
