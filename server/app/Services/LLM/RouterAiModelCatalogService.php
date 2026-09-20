<?php

declare(strict_types=1);

namespace App\Services\LLM;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** RouterAI catalog is informational; refresh never writes LLM settings. */
final class RouterAiModelCatalogService
{
    private const CACHE_KEY = 'llm:routerai:model_catalog:v1';

    private const FRESH_SECONDS = 14400;

    public function __construct(private readonly LLMSettingsRepository $settings) {}

    public function snapshot(bool $refresh = false): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        $fresh = is_array($cached) && is_int($cached['fetched_at'] ?? null)
            && time() - $cached['fetched_at'] < self::FRESH_SECONDS;

        if ($refresh || ! $fresh) {
            try {
                $models = $this->fetch();
                $cached = ['fetched_at' => time(), 'models' => $models];
                // Retain the last valid catalog when the upstream is unavailable.
                Cache::forever(self::CACHE_KEY, $cached);
                $fresh = true;
            } catch (\Throwable) {
                return [
                    'status' => is_array($cached) ? 'stale' : 'unavailable',
                    'fetched_at' => is_array($cached) ? date(DATE_ATOM, $cached['fetched_at']) : null,
                    'models' => is_array($cached) ? $cached['models'] : [],
                    'error_code' => 'provider_connection_failed',
                ];
            }
        }

        return [
            'status' => $fresh ? 'fresh' : 'stale',
            'fetched_at' => date(DATE_ATOM, $cached['fetched_at']),
            'models' => $cached['models'],
            'error_code' => null,
        ];
    }

    public function cachedModel(string $id): ?array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (! is_array($cached)) {
            return null;
        }

        foreach ($cached['models'] ?? [] as $model) {
            if (($model['id'] ?? null) === $id) {
                return $model;
            }
        }

        return null;
    }

    public function cachedStatus(): string
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (! is_array($cached) || ! is_int($cached['fetched_at'] ?? null)) {
            return 'unavailable';
        }

        return time() - $cached['fetched_at'] < self::FRESH_SECONDS ? 'fresh' : 'stale';
    }

    private function fetch(): array
    {
        $baseUrl = rtrim((string) ($this->settings->getProviderSettings('routerai')['base_url'] ?? ProviderRegistry::getDefaultBaseUrl('routerai')), '/');
        $response = Http::connectTimeout(5)->timeout(15)->get($baseUrl.'/models');
        if (! $response->successful()) {
            throw new \RuntimeException('RouterAI catalog request failed');
        }

        $rows = $response->json('data');
        if (! is_array($rows) || ! array_is_list($rows) || $rows === []) {
            throw new \UnexpectedValueException('RouterAI catalog has invalid structure');
        }

        $models = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['id'] ?? null) || $row['id'] === '') {
                continue;
            }
            $architecture = is_array($row['architecture'] ?? null) ? $row['architecture'] : [];
            $parameters = is_array($row['supported_parameters'] ?? null) ? $row['supported_parameters'] : [];
            $models[$row['id']] = [
                'id' => $row['id'],
                'display_name' => is_string($row['name'] ?? null) ? $row['name'] : null,
                'author' => str_contains($row['id'], '/') ? explode('/', $row['id'], 2)[0] : null,
                'family' => is_string($row['family'] ?? null) ? $row['family'] : null,
                'provider' => is_string($row['provider'] ?? null) ? $row['provider'] : null,
                'context_length' => is_int($row['context_length'] ?? null) ? $row['context_length'] : null,
                'input_modalities' => $this->strings($architecture['input_modalities'] ?? null),
                'output_modalities' => $this->strings($architecture['output_modalities'] ?? null),
                'supported_parameters' => $this->strings($parameters),
                'streaming' => is_bool($row['streaming'] ?? null) ? $row['streaming'] : null,
                'status' => is_string($row['status'] ?? null) ? $row['status'] : null,
                'pricing' => $this->pricing($row['pricing'] ?? null),
                'pricing_units' => $this->pricing($row['pricing_units'] ?? null),
            ];
        }
        if ($models === []) {
            throw new \UnexpectedValueException('RouterAI catalog has no usable models');
        }

        return array_values($models);
    }

    private function strings(mixed $value): ?array
    {
        return is_array($value) && array_is_list($value)
            ? array_values(array_filter($value, 'is_string')) : null;
    }

    private function pricing(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        return array_filter($value, static fn (mixed $item): bool => is_string($item) || is_int($item) || is_float($item));
    }
}
