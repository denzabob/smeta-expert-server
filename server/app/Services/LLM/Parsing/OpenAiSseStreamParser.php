<?php

declare(strict_types=1);

namespace App\Services\LLM\Parsing;

use App\Services\LLM\DTO\LLMCancellationToken;
use App\Services\LLM\DTO\LLMStreamEvent;
use App\Services\LLM\Exceptions\LLMProviderException;
use App\Services\LLM\RouterAiFileAnnotationParser;

/** OpenAI-compatible SSE framing parser. Network chunks are deliberately opaque. */
final class OpenAiSseStreamParser
{
    public function __construct(private readonly bool $allowReasoningSummary = false) {}

    /** @param iterable<string> $chunks @return iterable<LLMStreamEvent> */
    public function parse(iterable $chunks, LLMCancellationToken $token): iterable
    {
        $buffer = '';
        $dataLines = [];
        $metadata = [];
        $parsedFiles = [];

        $dispatch = function (array $lines) use (&$metadata, &$parsedFiles): array {
            $payload = implode("\n", $lines);
            if ($payload === '[DONE]') {
                return ['done' => true];
            }
            try {
                $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new LLMProviderException('Malformed SSE frame', 'openai-compatible', 'stream_malformed');
            }
            if (! is_array($decoded)) {
                return [];
            }
            $usage = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];
            $metadata = array_filter([
                ...$metadata,
                'upstream_id' => is_string($decoded['id'] ?? null) ? $decoded['id'] : ($metadata['upstream_id'] ?? null),
                'provider' => is_string($decoded['provider'] ?? null) ? $decoded['provider'] : ($metadata['provider'] ?? null),
                'service_tier' => is_string($decoded['service_tier'] ?? null) ? $decoded['service_tier'] : ($metadata['service_tier'] ?? null),
                'prompt_tokens' => $usage['prompt_tokens'] ?? ($metadata['prompt_tokens'] ?? null),
                'completion_tokens' => $usage['completion_tokens'] ?? ($metadata['completion_tokens'] ?? null),
                'total_tokens' => $usage['total_tokens'] ?? ($metadata['total_tokens'] ?? null),
                'cached_tokens' => $usage['prompt_tokens_details']['cached_tokens'] ?? ($metadata['cached_tokens'] ?? null),
                'reasoning_tokens' => $usage['completion_tokens_details']['reasoning_tokens'] ?? ($metadata['reasoning_tokens'] ?? null),
            ], static fn (mixed $value): bool => $value !== null);
            $choice = is_array($decoded['choices'][0] ?? null) ? $decoded['choices'][0] : [];
            $delta = is_array($choice['delta'] ?? null) ? $choice['delta'] : [];
            $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];
            $annotations = $message['annotations'] ?? $delta['annotations'] ?? null;
            foreach (RouterAiFileAnnotationParser::parse($annotations) as $parsed) {
                $parsedFiles[strtolower($parsed->sha256)] = $parsed;
            }

            $content = $delta['content'] ?? null;
            if (is_array($content)) {
                $content = implode('', array_filter(array_map(static fn (mixed $part): string => is_array($part) && is_string($part['text'] ?? null) ? $part['text'] : '', $content)));
            }

            // Generic reasoning fields are intentionally not inspected. A
            // dedicated field is accepted only after the provider capability
            // has been explicitly enabled by its integration.
            $summary = $this->allowReasoningSummary
                ? ($delta['reasoning_summary'] ?? $message['reasoning_summary'] ?? null)
                : null;

            return array_filter([
                'text' => is_string($content) && $content !== '' ? $content : null,
                'summary' => is_string($summary) && $summary !== '' ? mb_substr($summary, 0, 4000, 'UTF-8') : null,
                'summary_final' => array_key_exists('reasoning_summary', $message),
            ], static fn (mixed $value): bool => $value !== null && $value !== false);
        };

        foreach ($chunks as $chunk) {
            if ($token->isCancellationRequested()) {
                return;
            }
            $buffer .= $chunk;
            while (($position = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $position), "\r");
                $buffer = substr($buffer, $position + 1);
                if ($line === '') {
                    if ($dataLines === []) {
                        continue;
                    }
                    $event = $dispatch($dataLines);
                    $dataLines = [];
                    if (($event['done'] ?? false) === true) {
                        yield LLMStreamEvent::done($metadata, array_values($parsedFiles));

                        return;
                    }
                    if (is_string($event['text'] ?? null)) {
                        yield LLMStreamEvent::delta($event['text']);
                    }
                    if (is_string($event['summary'] ?? null)) {
                        yield LLMStreamEvent::reasoningSummary($event['summary'], (bool) ($event['summary_final'] ?? false));
                    }
                } elseif (str_starts_with($line, ':')) {
                    yield LLMStreamEvent::heartbeat();
                } elseif (str_starts_with($line, 'data:')) {
                    $dataLines[] = ltrim(substr($line, 5));
                }
            }
        }
        if ($dataLines !== []) {
            $event = $dispatch($dataLines);
            if (($event['done'] ?? false) === true) {
                yield LLMStreamEvent::done($metadata, array_values($parsedFiles));

                return;
            }
            if (is_string($event['text'] ?? null)) {
                yield LLMStreamEvent::delta($event['text']);
            }
            if (is_string($event['summary'] ?? null)) {
                yield LLMStreamEvent::reasoningSummary($event['summary'], (bool) ($event['summary_final'] ?? false));
            }
        }

        // EOF is not a successful SSE terminal. In particular, annotations
        // observed before it must never be treated as completed OCR output.
        if (! $token->isCancellationRequested()) {
            throw new LLMProviderException('Stream ended without terminal marker', 'openai-compatible', 'stream_eof_without_terminal');
        }
    }
}
