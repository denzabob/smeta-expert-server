<?php

declare(strict_types=1);

namespace App\Services\LLM;

use App\Services\LLM\Enums\LLMCapability;

/** Dynamic model facts > explicit local override > legacy provider/model catalog. */
final class LLMEffectiveCapabilityResolver
{
    public function __construct(private readonly RouterAiModelCatalogService $catalog) {}

    /** @return array<string, bool> */
    public function resolve(string $provider, string $model, ?array $catalogEntry = null): array
    {
        $legacy = LLMCapabilityCatalog::forProviderModel($provider, $model);
        $result = [];
        foreach (LLMCapability::cases() as $capability) {
            $result[$capability->value] = in_array($capability, $legacy, true);
        }

        $overrides = config("services.{$provider}.capability_overrides.{$model}");
        if (is_array($overrides)) {
            foreach ($overrides as $name => $supported) {
                if (LLMCapability::tryFrom((string) $name) !== null && is_bool($supported)) {
                    $result[$name] = $supported;
                }
            }
        }

        $entry = $provider === 'routerai' ? ($catalogEntry ?? $this->catalog->cachedModel($model)) : null;
        if ($entry !== null) {
            $inputs = $entry['input_modalities'] ?? null;
            $outputs = $entry['output_modalities'] ?? null;
            if (is_array($inputs)) {
                $result['text_input'] = in_array('text', $inputs, true)
                    && (! is_array($outputs) || in_array('text', $outputs, true));
                $result['image_input'] = in_array('image', $inputs, true);
                $result['file_input'] = in_array('file', $inputs, true);
            }
            if (is_array($outputs) && ! in_array('text', $outputs, true)) {
                $result['text_input'] = false;
            }
            $parameters = $entry['supported_parameters'] ?? null;
            if (is_array($parameters)) {
                $result['reasoning'] = in_array('reasoning', $parameters, true);
                $result['tools'] = in_array('tools', $parameters, true);
                $result['structured_output'] = in_array('structured_outputs', $parameters, true)
                    || in_array('response_format', $parameters, true);
            }
            if (is_bool($entry['streaming'] ?? null)) {
                $result['streaming'] = $entry['streaming'];
            }
        }

        // OCR is a RouterAI file-parser gateway path, not a model feature.
        $result['pdf_ocr'] = $provider === 'routerai'
            && (bool) config('expert.pdf_ocr.enabled', true)
            && $result['text_input'];

        return $result;
    }

    public function source(string $provider, string $model, LLMCapability $capability): string
    {
        if ($provider === 'routerai') {
            if ($capability === LLMCapability::PDF_OCR) {
                return 'routerai_gateway';
            }
            $entry = $this->catalog->cachedModel($model);
            if ($entry !== null && $this->catalogDefines($entry, $capability)) {
                return 'dynamic_catalog';
            }
        }

        $overrides = config("services.{$provider}.capability_overrides.{$model}");
        if (is_array($overrides) && array_key_exists($capability->value, $overrides)) {
            return 'local_override';
        }

        return 'legacy_catalog';
    }

    private function catalogDefines(array $entry, LLMCapability $capability): bool
    {
        return match ($capability) {
            LLMCapability::TEXT_INPUT, LLMCapability::IMAGE_INPUT, LLMCapability::FILE_INPUT => is_array($entry['input_modalities'] ?? null),
            LLMCapability::REASONING, LLMCapability::TOOLS, LLMCapability::STRUCTURED_OUTPUT => is_array($entry['supported_parameters'] ?? null),
            LLMCapability::STREAMING => is_bool($entry['streaming'] ?? null),
            default => false,
        };
    }
}
