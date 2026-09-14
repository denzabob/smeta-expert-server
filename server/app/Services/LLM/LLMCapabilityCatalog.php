<?php

declare(strict_types=1);

namespace App\Services\LLM;

use App\Services\LLM\Enums\LLMCapability;
use Illuminate\Support\Str;

final class LLMCapabilityCatalog
{
    /** @return list<LLMCapability> */
    public static function forProviderModel(string $provider, string $model): array
    {
        $configured = config("services.{$provider}.capabilities", [LLMCapability::TEXT_INPUT->value]);
        $capabilities = is_array($configured) ? $configured : [];
        $modelCapabilities = config("services.{$provider}.model_capabilities", []);

        if (is_array($modelCapabilities)) {
            foreach ($modelCapabilities as $pattern => $additionalCapabilities) {
                if (is_string($pattern) && Str::is($pattern, $model) && is_array($additionalCapabilities)) {
                    $capabilities = [...$capabilities, ...$additionalCapabilities];
                }
            }
        }

        $resolved = [];
        foreach (array_values(array_unique($capabilities)) as $capability) {
            if (! is_string($capability)) {
                continue;
            }

            $enum = LLMCapability::tryFrom($capability);
            if ($enum !== null) {
                $resolved[] = $enum;
            }
        }

        return $resolved;
    }
}
