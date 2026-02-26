<?php

declare(strict_types=1);

namespace App\Services\LLM;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Репозиторий настроек LLM
 * 
 * Приоритет настроек:
 * 1. app_settings (если заполнено)
 * 2. env fallback
 * 
 * API ключи хранятся зашифрованно через Laravel Crypt.
 */
class LLMSettingsRepository
{
    private const CACHE_KEY = 'llm:settings';
    private const CACHE_TTL = 300; // 5 minutes

    private const SETTINGS_KEYS = [
        'llm.primary_provider',
        'llm.fallback_providers',
        'llm.mode',
        'llm.providers',
    ];

    private const ENCRYPTED_FIELDS = ['api_key'];

    /**
     * Получить режим работы (manual|auto)
     */
    public function getMode(): string
    {
        $mode = $this->get('llm.mode');
        return in_array($mode, ['manual', 'auto']) ? $mode : 'auto';
    }

    /**
     * Получить primary провайдера
     */
    public function getPrimaryProvider(): string
    {
        return $this->get('llm.primary_provider') ?? 'openrouter';
    }

    /**
     * Получить список fallback провайдеров
     */
    public function getFallbackProviders(): array
    {
        $providers = $this->get('llm.fallback_providers');
        return is_array($providers) ? $providers : ['deepseek'];
    }

    /**
     * Получить настройки провайдера
     */
    public function getProviderSettings(string $providerName): array
    {
        $providers = $this->get('llm.providers') ?? [];
        $settings = $providers[$providerName] ?? [];

        // Fallback на env если настройки пустые
        if (empty($settings['api_key'])) {
            $settings = $this->getEnvFallback($providerName);
        }

        return $settings;
    }

    /**
     * Получить все настройки для админки (без расшифрованных ключей)
     */
    public function getAllForAdmin(): array
    {
        return [
            'mode' => $this->getMode(),
            'primary_provider' => $this->getPrimaryProvider(),
            'fallback_providers' => $this->getFallbackProviders(),
            'providers' => $this->getProvidersForAdmin(),
        ];
    }

    /**
     * Получить настройки провайдеров для админки (ключи маскированы)
     */
    private function getProvidersForAdmin(): array
    {
        $providers = $this->get('llm.providers') ?? [];
        $result = [];

        // Используем ProviderRegistry для получения всех зарегистрированных провайдеров
        foreach (ProviderRegistry::names() as $name) {
            $settings = $providers[$name] ?? $this->getEnvFallback($name);

            $result[$name] = [
                'api_key_set' => !empty($settings['api_key']),
                'api_key_masked' => $this->maskApiKey($settings['api_key'] ?? ''),
                'base_url' => $settings['base_url'] ?? ProviderRegistry::getDefaultBaseUrl($name),
                'model' => $settings['model'] ?? ProviderRegistry::getDefaultModel($name),
                'is_env_fallback' => empty($providers[$name]['api_key']),
            ];
        }

        return $result;
    }

    /**
     * Сохранить настройки из админки
     */
    public function saveFromAdmin(array $data): void
    {
        // Сохраняем mode
        if (isset($data['mode'])) {
            $this->set('llm.mode', $data['mode']);
        }

        // Сохраняем primary_provider
        if (isset($data['primary_provider'])) {
            $this->set('llm.primary_provider', $data['primary_provider']);
        }

        // Сохраняем fallback_providers
        if (isset($data['fallback_providers'])) {
            $this->set('llm.fallback_providers', $data['fallback_providers']);
        }

        // Сохраняем настройки провайдеров
        if (isset($data['providers'])) {
            $existingProviders = $this->get('llm.providers') ?? [];

            foreach ($data['providers'] as $name => $settings) {
                // Если api_key не передан или пустой — сохраняем старый
                if (empty($settings['api_key']) && isset($existingProviders[$name]['api_key'])) {
                    $settings['api_key'] = $existingProviders[$name]['api_key'];
                }

                $existingProviders[$name] = $settings;
            }

            $this->set('llm.providers', $existingProviders);
        }

        // Очищаем кеш
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Получить значение настройки
     */
    private function get(string $key): mixed
    {
        $settings = $this->loadSettings();
        return $settings[$key] ?? null;
    }

    /**
     * Установить значение настройки
     */
    private function set(string $key, mixed $value): void
    {
        // Шифруем api_key в providers
        if ($key === 'llm.providers' && is_array($value)) {
            foreach ($value as $provider => $settings) {
                if (!empty($settings['api_key']) && !$this->isEncrypted($settings['api_key'])) {
                    $value[$provider]['api_key'] = Crypt::encryptString($settings['api_key']);
                }
            }
        }

        $jsonValue = json_encode($value);

        DB::table('app_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => $jsonValue, 'updated_at' => now()]
        );

        // Очищаем кеш
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Загрузить все настройки из БД
     */
    private function loadSettings(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            $rows = DB::table('app_settings')
                ->whereIn('key', self::SETTINGS_KEYS)
                ->pluck('value', 'key');

            $settings = [];

            foreach ($rows as $key => $jsonValue) {
                $value = json_decode($jsonValue, true);

                // Расшифровываем api_key в providers
                if ($key === 'llm.providers' && is_array($value)) {
                    foreach ($value as $provider => $providerSettings) {
                        if (!empty($providerSettings['api_key'])) {
                            try {
                                $value[$provider]['api_key'] = Crypt::decryptString($providerSettings['api_key']);
                            } catch (\Throwable $e) {
                                Log::warning("Failed to decrypt API key for {$provider}", ['error' => $e->getMessage()]);
                                $value[$provider]['api_key'] = '';
                            }
                        }
                    }
                }

                $settings[$key] = $value;
            }

            return $settings;
        });
    }

    /**
     * Получить настройки из ENV (fallback)
     */
    private function getEnvFallback(string $providerName): array
    {
        $defaults = [
            'api_key' => config("services.{$providerName}.key", ''),
            'base_url' => config("services.{$providerName}.base_url", ProviderRegistry::getDefaultBaseUrl($providerName)),
            'model' => config("services.{$providerName}.model", ProviderRegistry::getDefaultModel($providerName)),
            'temperature' => (float) config("services.{$providerName}.temperature", 0.2),
            'max_tokens' => (int) config("services.{$providerName}.max_tokens", 4096),
        ];

        return $defaults;
    }

    /**
     * Получить дефолтный base_url для провайдера
     */
    private function getDefaultBaseUrl(string $providerName): string
    {
        return ProviderRegistry::getDefaultBaseUrl($providerName);
    }

    /**
     * Получить дефолтную модель для провайдера
     */
    private function getDefaultModel(string $providerName): string
    {
        return ProviderRegistry::getDefaultModel($providerName);
    }

    /**
     * Маскировать API ключ для отображения
     */
    private function maskApiKey(string $apiKey): string
    {
        if (empty($apiKey)) {
            return '';
        }

        $length = strlen($apiKey);
        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return substr($apiKey, 0, 4) . str_repeat('*', $length - 8) . substr($apiKey, -4);
    }

    /**
     * Проверить, зашифрована ли строка
     */
    private function isEncrypted(string $value): bool
    {
        // Laravel Crypt создает base64 строки определенного формата
        return str_starts_with($value, 'eyJ');
    }
}
