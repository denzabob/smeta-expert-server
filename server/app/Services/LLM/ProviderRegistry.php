<?php

declare(strict_types=1);

namespace App\Services\LLM;

use App\Services\LLM\Contracts\LLMProviderInterface;
use App\Services\LLM\Providers\DeepSeekProvider;
use App\Services\LLM\Providers\MistralProvider;
use App\Services\LLM\Providers\OpenRouterProvider;

/**
 * Реестр LLM провайдеров
 * 
 * Централизованное место для регистрации и получения провайдеров.
 * Для добавления нового провайдера:
 * 1. Создать класс провайдера, реализующий LLMProviderInterface
 * 2. Добавить в PROVIDERS с метаданными
 * 3. Добавить в getProviderClass()
 */
class ProviderRegistry
{
    /**
     * Зарегистрированные провайдеры с метаданными
     */
    public const PROVIDERS = [
        'openrouter' => [
            'name' => 'OpenRouter',
            'description' => 'Unified API для 100+ моделей (GPT-4, Claude, Gemini, etc.)',
            'icon' => 'mdi-cloud',
            'default_base_url' => 'https://openrouter.ai/api/v1',
            'default_model' => 'google/gemini-2.0-flash-001',
            'supports_json_mode' => true,
            'docs_url' => 'https://openrouter.ai/docs',
        ],
        'deepseek' => [
            'name' => 'DeepSeek',
            'description' => 'DeepSeek AI — мощные и недорогие модели',
            'icon' => 'mdi-brain',
            'default_base_url' => 'https://api.deepseek.com/v1',
            'default_model' => 'deepseek-chat',
            'supports_json_mode' => true,
            'docs_url' => 'https://platform.deepseek.com/api-docs',
        ],
        'mistral' => [
            'name' => 'Mistral AI',
            'description' => 'Mistral/Mixtral — быстрые европейские модели',
            'icon' => 'mdi-weather-windy',
            'default_base_url' => 'https://api.mistral.ai/v1',
            'default_model' => 'mistral-small-latest',
            'supports_json_mode' => true,
            'docs_url' => 'https://docs.mistral.ai/api/',
        ],
    ];

    /**
     * Получить список всех доступных провайдеров
     */
    public static function all(): array
    {
        return self::PROVIDERS;
    }

    /**
     * Получить список имён провайдеров
     */
    public static function names(): array
    {
        return array_keys(self::PROVIDERS);
    }

    /**
     * Получить метаданные провайдера
     */
    public static function getMeta(string $name): ?array
    {
        return self::PROVIDERS[$name] ?? null;
    }

    /**
     * Проверить, существует ли провайдер
     */
    public static function exists(string $name): bool
    {
        return isset(self::PROVIDERS[$name]);
    }

    /**
     * Получить класс провайдера
     */
    public static function getProviderClass(string $name): ?string
    {
        return match ($name) {
            'openrouter' => OpenRouterProvider::class,
            'deepseek' => DeepSeekProvider::class,
            'mistral' => MistralProvider::class,
            default => null,
        };
    }

    /**
     * Создать экземпляр провайдера
     */
    public static function createProvider(string $name, array $settings): ?LLMProviderInterface
    {
        $class = self::getProviderClass($name);

        if ($class === null) {
            return null;
        }

        return $class::fromSettings($settings);
    }

    /**
     * Получить дефолтный base_url для провайдера
     */
    public static function getDefaultBaseUrl(string $name): string
    {
        return self::PROVIDERS[$name]['default_base_url'] ?? '';
    }

    /**
     * Получить дефолтную модель для провайдера
     */
    public static function getDefaultModel(string $name): string
    {
        return self::PROVIDERS[$name]['default_model'] ?? '';
    }

    /**
     * Получить данные для фронтенда (UI)
     */
    public static function getForUI(): array
    {
        $result = [];

        foreach (self::PROVIDERS as $key => $meta) {
            $result[] = [
                'value' => $key,
                'title' => $meta['name'],
                'description' => $meta['description'],
                'icon' => $meta['icon'],
                'docs_url' => $meta['docs_url'],
            ];
        }

        return $result;
    }
}
