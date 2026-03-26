<?php

declare(strict_types=1);

namespace App\Services\LLM;

use App\Models\AiLog;
use App\Services\LLM\DTO\LLMProviderState;
use App\Services\LLM\Enums\CircuitState;
use App\Services\LLM\Enums\UnavailableReason;
use Illuminate\Support\Facades\DB;

/**
 * Сервис агрегации состояния LLM-провайдеров.
 *
 * Единственная точка для получения актуального состояния всех провайдеров:
 * — конфигурация (LLMSettingsRepository)
 * — здоровье (CircuitBreaker)
 * — метаданные (ProviderRegistry)
 * — runtime-метрики (ai_logs)
 */
class LLMProviderStateService
{
    public function __construct(
        private LLMSettingsRepository $settings,
        private CircuitBreaker $circuitBreaker,
    ) {}

    /**
     * Получить состояние всех провайдеров.
     *
     * @return LLMProviderState[]
     */
    public function getAllStates(): array
    {
        $executionPlan = $this->getExecutionPlan();
        $metrics = $this->loadRecentMetrics();

        $states = [];
        foreach (ProviderRegistry::names() as $index => $name) {
            $states[] = $this->buildState($name, $executionPlan, $metrics, $index);
        }

        return $states;
    }

    /**
     * Получить состояние одного провайдера.
     */
    public function getState(string $provider): LLMProviderState
    {
        $executionPlan = $this->getExecutionPlan();
        $metrics = $this->loadRecentMetrics();
        $index = array_search($provider, ProviderRegistry::names(), true);

        return $this->buildState($provider, $executionPlan, $metrics, $index !== false ? $index : 99);
    }

    /**
     * Валидация текущей конфигурации.
     *
     * @return array{valid: bool, errors: string[], warnings: string[]}
     */
    public function validateConfiguration(): array
    {
        $errors = [];
        $warnings = [];

        $primary = $this->settings->getPrimaryProvider();
        $fallbacks = $this->settings->getFallbackProviders();

        // primary существует в реестре
        if (!ProviderRegistry::exists($primary)) {
            $errors[] = "Primary provider '{$primary}' is not registered.";
        }

        // primary настроен (api key)
        $primarySettings = $this->settings->getProviderSettings($primary);
        if (empty($primarySettings['api_key'])) {
            $errors[] = "Primary provider '{$primary}' has no API key configured.";
        }

        // fallback не содержит primary
        if (in_array($primary, $fallbacks, true)) {
            $errors[] = "Fallback list must not contain the primary provider '{$primary}'.";
        }

        // дубликаты в fallback
        if (count($fallbacks) !== count(array_unique($fallbacks))) {
            $errors[] = 'Fallback list contains duplicates.';
        }

        // все fallback существуют в реестре
        foreach ($fallbacks as $fb) {
            if (!ProviderRegistry::exists($fb)) {
                $errors[] = "Fallback provider '{$fb}' is not registered.";
            }
        }

        // хотя бы один валидный провайдер
        $validCount = 0;
        $validFallbackCount = 0;
        foreach (array_merge([$primary], $fallbacks) as $idx => $name) {
            $s = $this->settings->getProviderSettings($name);
            if (!empty($s['api_key'])) {
                $validCount++;
                if ($idx > 0) {
                    $validFallbackCount++;
                }
            }
        }

        if ($validCount === 0) {
            $errors[] = 'No configured providers with a valid API key.';
        }

        // Предупреждение: все fallback-ы невалидны
        if (count($fallbacks) > 0 && $validFallbackCount === 0) {
            $warnings[] = 'None of the fallback providers have a valid API key configured.';
        }

        // Circuit breaker для primary открыт
        $primaryCircuit = $this->circuitBreaker->getCircuitState($primary);
        if ($primaryCircuit === CircuitState::OPEN) {
            $warnings[] = "Primary provider '{$primary}' circuit breaker is OPEN.";
        }

        // mode=manual но primary не работает
        if ($this->settings->getMode() === 'manual' && !empty($primarySettings['api_key'])
            && $primaryCircuit === CircuitState::OPEN) {
            $warnings[] = "Manual mode is active but primary provider '{$primary}' is down. Consider switching to auto mode.";
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Получить execution plan: primary + fallback в порядке приоритета.
     * Фильтрует недоступных провайдеров (не настроены, circuit OPEN).
     *
     * @return string[]
     */
    public function getExecutionPlan(): array
    {
        $mode = $this->settings->getMode();
        $primary = $this->settings->getPrimaryProvider();

        if ($mode === 'manual') {
            return [$primary];
        }

        $fallbacks = $this->settings->getFallbackProviders();
        $fullPlan = array_values(array_unique(array_merge([$primary], $fallbacks)));

        // Фильтр: убираем провайдеров с circuit OPEN или без ключа
        $healthyPlan = [];
        $skippedReasons = [];

        foreach ($fullPlan as $name) {
            $settings = $this->settings->getProviderSettings($name);
            $circuit = $this->circuitBreaker->getCircuitState($name);

            if (empty($settings['api_key'])) {
                $skippedReasons[$name] = 'not_configured';
                continue;
            }

            if ($circuit === CircuitState::OPEN) {
                $skippedReasons[$name] = 'circuit_open';
                continue;
            }

            $healthyPlan[] = $name;
        }

        // Всегда хотя бы primary (даже если down — пусть попробует)
        if (empty($healthyPlan) && !empty($fullPlan)) {
            $healthyPlan = [$primary];
        }

        return $healthyPlan;
    }

    /**
     * Полный (нефильтрованный) execution plan для отображения в UI.
     *
     * @return string[]
     */
    public function getFullExecutionPlan(): array
    {
        $mode = $this->settings->getMode();
        $primary = $this->settings->getPrimaryProvider();

        if ($mode === 'manual') {
            return [$primary];
        }

        $fallbacks = $this->settings->getFallbackProviders();
        return array_values(array_unique(array_merge([$primary], $fallbacks)));
    }

    // -------------------------------------------------------------------
    // Internal / builders
    // -------------------------------------------------------------------

    private function buildState(
        string $name,
        array $executionPlan,
        array $metrics,
        int $registryIndex
    ): LLMProviderState {
        $meta = ProviderRegistry::getMeta($name);
        $providerSettings = $this->settings->getProviderSettings($name);
        $circuitState = $this->circuitBreaker->getCircuitState($name);
        $cbStats = $this->circuitBreaker->getStats($name);

        $isConfigured = !empty($providerSettings['api_key']);
        $isHealthy = $circuitState !== CircuitState::OPEN;
        $isAvailable = $isConfigured && $isHealthy;
        $usedInChain = in_array($name, $executionPlan, true);

        // Determine unavailable reason
        $unavailableReason = UnavailableReason::NONE;
        if (!$isAvailable) {
            if (!ProviderRegistry::exists($name)) {
                $unavailableReason = UnavailableReason::NOT_CONFIGURED;
            } elseif (empty($providerSettings['api_key'])) {
                $unavailableReason = UnavailableReason::NO_API_KEY;
            } elseif ($circuitState === CircuitState::OPEN) {
                $unavailableReason = UnavailableReason::CIRCUIT_OPEN;
            } else {
                $unavailableReason = UnavailableReason::INVALID_CONFIG;
            }
        }

        // Source: откуда ключ
        $allDbProviders = $this->getDbProviderKeys();
        $source = 'none';
        if (!empty($providerSettings['api_key'])) {
            $source = isset($allDbProviders[$name]) ? 'db' : 'env';
        }

        // Priority в execution plan
        $planIndex = array_search($name, $executionPlan, true);
        $priority = $planIndex !== false ? $planIndex : $registryIndex + 100;

        $m = $metrics[$name] ?? null;

        return new LLMProviderState(
            provider: $name,
            displayName: $meta['name'] ?? $name,
            isConfigured: $isConfigured,
            isHealthy: $isHealthy,
            isAvailable: $isAvailable,
            circuitState: $circuitState,
            failCount: $cbStats['fail_count'] ?? 0,
            lastError: $cbStats['last_error'] ?? null,
            lastErrorAt: $cbStats['last_failure_at'] ?? null,
            source: $source,
            model: $providerSettings['model'] ?? ProviderRegistry::getDefaultModel($name),
            baseUrl: $providerSettings['base_url'] ?? ProviderRegistry::getDefaultBaseUrl($name),
            priority: (int) $priority,
            usedInChain: $usedInChain,
            unavailableReason: $unavailableReason,
            avgLatencyMs: $m['avg_latency'] ?? null,
            errorRate: $m['error_rate'] ?? null,
            lastSuccessAt: $cbStats['last_success_at'] ?? null,
            usagePercentage: $m['usage_percentage'] ?? null,
        );
    }

    /**
     * Получить метрики из ai_logs за последние 24 ч.
     *
     * @return array<string, array{avg_latency: float, error_rate: float, usage_percentage: float}>
     */
    private function loadRecentMetrics(): array
    {
        $rows = AiLog::query()
            ->select('provider_name')
            ->selectRaw('AVG(CASE WHEN is_successful THEN latency_ms ELSE NULL END) as avg_latency')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN is_successful = false THEN 1 ELSE 0 END) as failures')
            ->where('created_at', '>=', now()->subHours(24))
            ->whereNotNull('provider_name')
            ->groupBy('provider_name')
            ->get();

        // Считаем общее количество запросов для usage_percentage
        $grandTotal = $rows->sum('total');

        $metrics = [];
        foreach ($rows as $row) {
            $total = (int) $row->total;
            $metrics[$row->provider_name] = [
                'avg_latency' => $row->avg_latency !== null ? (float) $row->avg_latency : null,
                'error_rate' => $total > 0 ? (float) $row->failures / $total : 0.0,
                'usage_percentage' => $grandTotal > 0 ? (float) $total / $grandTotal : 0.0,
            ];
        }

        return $metrics;
    }

    /**
     * Вернуть провайдеров, у которых ключ хранится в БД (app_settings).
     *
     * @return array<string, bool>
     */
    private function getDbProviderKeys(): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $raw = DB::table('app_settings')
            ->where('key', 'llm.providers')
            ->value('value');

        if ($raw === null) {
            return $cache = [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $cache = [];
        }

        $result = [];
        foreach ($decoded as $name => $settings) {
            if (!empty($settings['api_key'])) {
                $result[$name] = true;
            }
        }

        return $cache = $result;
    }
}
