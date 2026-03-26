<?php

namespace App\Providers;

use App\Services\LLM\CircuitBreaker;
use App\Services\LLM\Contracts\LLMProviderInterface;
use App\Services\LLM\LLMErrorClassifier;
use App\Services\LLM\LLMProviderStateService;
use App\Services\LLM\LLMRouter;
use App\Services\LLM\LLMSettingsRepository;
use App\Services\LLM\Parsing\LLMJsonParser;
use App\Services\LLM\Prompts\DecompositionPromptBuilder;
use App\Services\LLM\Providers\DeepSeekProvider;
use App\Services\LLM\Providers\OpenRouterProvider;
use App\Services\LLM\Providers\RouterAiProvider;
use Illuminate\Support\ServiceProvider;

class LLMServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Singleton for settings repository (cached)
        $this->app->singleton(LLMSettingsRepository::class);

        // Singleton for circuit breaker (stateful via Redis)
        $this->app->singleton(CircuitBreaker::class);

        // Singleton for JSON parser (stateless)
        $this->app->singleton(LLMJsonParser::class);

        // Singleton for prompt builder (stateless)
        $this->app->singleton(DecompositionPromptBuilder::class);

        // Singleton for error classifier (stateless)
        $this->app->singleton(LLMErrorClassifier::class);

        // Singleton for provider state service
        $this->app->singleton(LLMProviderStateService::class, function ($app) {
            return new LLMProviderStateService(
                $app->make(LLMSettingsRepository::class),
                $app->make(CircuitBreaker::class),
            );
        });

        // Singleton for LLM Router
        $this->app->singleton(LLMRouter::class, function ($app) {
            return new LLMRouter(
                $app->make(CircuitBreaker::class),
                $app->make(LLMSettingsRepository::class),
                $app->make(LLMErrorClassifier::class),
            );
        });

        // Register providers (not singleton - created on demand with settings)
        $this->app->bind(OpenRouterProvider::class, function ($app) {
            $settings = $app->make(LLMSettingsRepository::class);
            return OpenRouterProvider::fromSettings($settings->getProviderSettings('openrouter'));
        });

        $this->app->bind(DeepSeekProvider::class, function ($app) {
            $settings = $app->make(LLMSettingsRepository::class);
            return DeepSeekProvider::fromSettings($settings->getProviderSettings('deepseek'));
        });

        $this->app->bind(RouterAiProvider::class, function ($app) {
            $settings = $app->make(LLMSettingsRepository::class);
            return RouterAiProvider::fromSettings($settings->getProviderSettings('routerai'));
        });

        // Default provider binding (based on primary setting)
        $this->app->bind(LLMProviderInterface::class, function ($app) {
            $settings = $app->make(LLMSettingsRepository::class);
            $primary = $settings->getPrimaryProvider();

            return match ($primary) {
                'openrouter' => $app->make(OpenRouterProvider::class),
                'deepseek' => $app->make(DeepSeekProvider::class),
                'routerai' => $app->make(RouterAiProvider::class),
                default => $app->make(OpenRouterProvider::class),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
