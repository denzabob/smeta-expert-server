<?php

declare(strict_types=1);

namespace Tests\Feature\Expert;

use App\Services\LLM\DTO\LLMCancellationToken;
use App\Services\LLM\DTO\LLMChatMessage;
use App\Services\LLM\DTO\LLMChatRequest;
use App\Services\LLM\Exceptions\LLMProviderException;
use App\Services\LLM\Providers\RouterAiProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

final class RouterAiProviderStreamContentTypeTest extends TestCase
{
    public function test_stream_rejects_successful_non_sse_response_without_exposing_its_body(): void
    {
        $provider = $this->provider(new Response(200, ['Content-Type' => 'application/json'], '{"error":"SECRET-UPSTREAM-BODY"}'));

        try {
            iterator_to_array($provider->streamChat($this->request(), $this->token()));
            $this->fail('Expected unexpected content type error.');
        } catch (LLMProviderException $exception) {
            $this->assertSame('unexpected_content_type', $exception->getErrorType());
            $this->assertStringNotContainsString('SECRET-UPSTREAM-BODY', $exception->getMessage());
        }
    }

    public function test_stream_accepts_sse_content_type_with_charset_and_keeps_happy_path(): void
    {
        $provider = $this->provider(new Response(200, ['Content-Type' => 'text/event-stream; charset=utf-8'], "data: {\"choices\":[{\"delta\":{\"content\":\"Ответ\"}}]}\n\ndata: [DONE]\n\n"));

        $events = iterator_to_array($provider->streamChat($this->request(), $this->token()));

        $this->assertSame('delta', $events[0]?->type);
        $this->assertSame('done', $events[1]?->type);
    }

    private function provider(Response $response): RouterAiProvider
    {
        return new RouterAiProvider(
            apiKey: 'test-key',
            baseUrl: 'https://routerai.test/api/v1',
            model: 'openai/gpt-4o',
            streamingClient: new Client(['handler' => HandlerStack::create(new MockHandler([$response]))]),
        );
    }

    private function request(): LLMChatRequest
    {
        return new LLMChatRequest('System.', [LLMChatMessage::text('user', 'Проверка.')]);
    }

    private function token(): LLMCancellationToken
    {
        return new LLMCancellationToken(static fn (): bool => false);
    }
}
