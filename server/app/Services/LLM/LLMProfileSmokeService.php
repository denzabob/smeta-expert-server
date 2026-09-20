<?php

declare(strict_types=1);

namespace App\Services\LLM;

use App\Services\LLM\Contracts\LLMStreamingProviderInterface;
use App\Services\LLM\DTO\LLMCancellationToken;
use App\Services\LLM\DTO\LLMChatMessage;
use App\Services\LLM\DTO\LLMChatRequest;
use App\Services\LLM\DTO\LLMFileContent;
use App\Services\LLM\DTO\LLMImageContent;
use App\Services\LLM\DTO\LLMTextContent;
use App\Services\LLM\Enums\LLMFileProcessingIntent;
use App\Services\LLM\Exceptions\LLMProviderException;

/** Controlled fixtures only. Results contain no request or upstream body. */
final class LLMProfileSmokeService
{
    /** @param (\Closure(string, array): ?\App\Services\LLM\Contracts\LLMProviderInterface)|null $providerFactory */
    public function __construct(
        private readonly LLMSettingsRepository $settings,
        private readonly ?\Closure $providerFactory = null,
    ) {}

    public function run(string $providerName, string $model, string $kind): array
    {
        $started = microtime(true);
        $result = ['status' => 'FAIL', 'checked_at' => now()->toIso8601String(), 'latency_ms' => null, 'ttft_ms' => null, 'error_code' => null];

        try {
            $settings = $this->settings->getProviderSettings($providerName);
            if (empty($settings['api_key'])) {
                throw new \RuntimeException('provider_auth_failed');
            }
            $settings['model'] = $model;
            $provider = $this->providerFactory !== null
                ? ($this->providerFactory)($providerName, $settings)
                : ProviderRegistry::createProvider($providerName, $settings);
            if ($provider === null) {
                throw new \RuntimeException('provider_validation_failed');
            }

            $request = match ($kind) {
                'text', 'streaming' => new LLMChatRequest('Диагностический тест. Ответь кратко.', [LLMChatMessage::text('user', 'Ответь: OK')]),
                'vision' => $this->visionRequest(),
                'pdf_ocr' => $this->pdfRequest(),
                default => throw new \RuntimeException('provider_validation_failed'),
            };

            if ($kind === 'streaming') {
                if (! $provider instanceof LLMStreamingProviderInterface) {
                    throw new \RuntimeException('streaming_not_supported');
                }
                $delta = false;
                $terminal = false;
                foreach ($provider->streamChat($request, new LLMCancellationToken(static fn (): bool => false)) as $event) {
                    if ($event->type === 'delta' && $event->text !== '') {
                        $delta = true;
                        $result['ttft_ms'] ??= (int) ((microtime(true) - $started) * 1000);
                    }
                    if ($event->type === 'done') {
                        $terminal = true;
                    }
                }
                if (! $delta || ! $terminal) {
                    throw new \RuntimeException('stream_parse_failed');
                }
            } else {
                $response = $provider->chat($request);
                if ($response->content === '') {
                    throw new \RuntimeException('provider_validation_failed');
                }
                if ($kind === 'pdf_ocr') {
                    $fixtureHash = hash('sha256', file_get_contents(resource_path('fixtures/llm-smoke-scanned.pdf')));
                    $matched = false;
                    foreach ($response->parsedFiles as $file) {
                        if ($file->sha256 === $fixtureHash && $file->text !== '') {
                            $matched = true;
                            break;
                        }
                    }
                    if (! $matched) {
                        throw new \RuntimeException('pdf_ocr_failed');
                    }
                }
            }
            $result['status'] = 'PASS';
        } catch (\Throwable $exception) {
            $result['error_code'] = $this->errorCode($exception, $kind);
        }

        $result['latency_ms'] = (int) ((microtime(true) - $started) * 1000);

        return $result;
    }

    private function visionRequest(): LLMChatRequest
    {
        $bytes = file_get_contents(resource_path('fixtures/llm-smoke.png'));
        if ($bytes === false) {
            throw new \RuntimeException('provider_validation_failed');
        }

        return new LLMChatRequest('Диагностический тест.', [new LLMChatMessage('user', [
            new LLMTextContent('Опиши изображение одним словом.'),
            new LLMImageContent('smoke-fixture', 'llm-smoke.png', 'image/png', $bytes, 64, 64),
        ])]);
    }

    private function pdfRequest(): LLMChatRequest
    {
        $bytes = file_get_contents(resource_path('fixtures/llm-smoke-scanned.pdf'));
        if ($bytes === false) {
            throw new \RuntimeException('provider_validation_failed');
        }

        return new LLMChatRequest('Диагностический тест.', [new LLMChatMessage('user', [
            new LLMTextContent('Прочитай содержимое PDF и ответь кратко.'),
            new LLMFileContent('llm-smoke-scanned.pdf', 'application/pdf', $bytes, hash('sha256', $bytes), LLMFileProcessingIntent::PDF_OCR),
        ])]);
    }

    private function errorCode(\Throwable $exception, string $kind): string
    {
        if ($exception instanceof LLMProviderException) {
            $status = $exception->getHttpStatus();

            return match (true) {
                $status === 401 || $status === 403 => 'provider_auth_failed',
                $status === 404 => 'provider_model_not_found',
                $status === 429 => 'provider_rate_limited',
                $exception->getErrorType() === 'timeout' => 'provider_timeout',
                $exception->getErrorType() === 'unexpected_content_type' => 'provider_unexpected_content_type',
                $exception->getErrorType() === 'stream_malformed' || $exception->getErrorType() === 'stream_eof_without_terminal' => 'stream_parse_failed',
                $exception->getErrorType() === 'network' => 'provider_connection_failed',
                default => $kind === 'pdf_ocr' ? 'pdf_ocr_failed' : 'provider_validation_failed',
            };
        }

        return in_array($exception->getMessage(), ['provider_auth_failed', 'provider_validation_failed', 'streaming_not_supported', 'stream_parse_failed', 'pdf_ocr_failed'], true)
            ? $exception->getMessage() : 'provider_connection_failed';
    }
}
