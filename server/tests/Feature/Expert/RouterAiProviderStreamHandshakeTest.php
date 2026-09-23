<?php

declare(strict_types=1);

namespace Tests\Feature\Expert;

use App\Services\LLM\DTO\LLMCancellationToken;
use App\Services\LLM\DTO\LLMChatMessage;
use App\Services\LLM\DTO\LLMChatRequest;
use App\Services\LLM\DTO\LLMFileContent;
use App\Services\LLM\Enums\LLMFileProcessingIntent;
use App\Services\LLM\Exceptions\LLMProviderException;
use App\Services\LLM\Providers\RouterAiProvider;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Response;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

final class RouterAiProviderStreamHandshakeTest extends TestCase
{
    public function test_pending_headers_timeout_closes_request_and_logs_safe_metrics(): void
    {
        config(['expert.streaming.provider_handshake_timeout_seconds' => 1]);
        $cancelled = false;
        $logs = [];
        Log::listen(static function (MessageLogged $event) use (&$logs): void {
            if (str_starts_with($event->message, 'RouterAI stream')) {
                $logs[$event->message][] = $event->context;
            }
        });
        $provider = $this->provider(static function (RequestInterface $request, array $options) use (&$cancelled): Promise {
            return new Promise(null, static function () use (&$cancelled): void {
                $cancelled = true;
            });
        });
        $request = $this->pdfRequest();

        try {
            iterator_to_array($provider->streamChat($request, new LLMCancellationToken(static fn (): bool => false, null, 'run-test')));
            $this->fail('Expected a bounded provider timeout.');
        } catch (LLMProviderException $exception) {
            $this->assertSame('timeout', $exception->getErrorType());
        }

        $this->assertTrue($cancelled);
        $start = $logs['RouterAI stream request starting'][0];
        $this->assertSame('run-test', $start['correlation_id']);
        $this->assertSame(['pdf_ocr'], $start['pdf_processing_intents']);
        $this->assertSame('mistral-ocr', $start['pdf_engine']);
        $this->assertSame(1, $start['file_count']);
        $this->assertSame(6, $start['raw_file_bytes']);
        $this->assertSame(8, $start['estimated_base64_payload_bytes']);
        $this->assertSame('provider_timeout', $logs['RouterAI stream request failed'][0]['error_code']);
        $this->assertStringNotContainsString('SECRET', json_encode($logs, JSON_THROW_ON_ERROR));
        $this->assertArrayNotHasKey('RouterAI stream response headers received', $logs);
    }

    public function test_cancellation_during_pending_headers_is_bounded(): void
    {
        $cancelled = false;
        $checks = 0;
        $provider = $this->provider(static function (RequestInterface $request, array $options) use (&$cancelled): Promise {
            return new Promise(null, static function () use (&$cancelled): void {
                $cancelled = true;
            });
        });
        $token = new LLMCancellationToken(static function () use (&$checks): bool {
            return ++$checks >= 3;
        });

        $this->assertSame([], iterator_to_array($provider->streamChat($this->textRequest(), $token)));
        $this->assertTrue($cancelled);
    }

    public function test_normal_text_stream_receives_headers_events_and_terminal_without_whole_stream_timeout(): void
    {
        config(['expert.streaming.provider_handshake_timeout_seconds' => 1]);
        $logs = [];
        Log::listen(static function (MessageLogged $event) use (&$logs): void {
            if (str_starts_with($event->message, 'RouterAI stream')) {
                $logs[] = $event->message;
            }
        });
        $provider = $this->provider(static fn (): FulfilledPromise => new FulfilledPromise(new Response(200, ['Content-Type' => 'text/event-stream'], "data: {\"choices\":[{\"delta\":{\"content\":\"Ответ\"}}]}\n\ndata: [DONE]\n\n")));

        $events = iterator_to_array($provider->streamChat($this->textRequest(), new LLMCancellationToken(static fn (): bool => false)));
        $this->assertSame(['delta', 'done'], array_map(static fn ($event): string => $event->type, $events));
        $this->assertSame([
            'RouterAI stream request starting',
            'RouterAI stream response headers received',
            'RouterAI stream first event',
            'RouterAI stream completed',
        ], $logs);
    }

    public function test_curl_transport_yields_first_token_before_upstream_finishes(): void
    {
        config(['expert.streaming.provider_handshake_timeout_seconds' => 1]);
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('Local process creation is unavailable.');
        }
        $server = <<<'PHP'
$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($listener === false) { exit(1); }
echo substr(strrchr(stream_socket_get_name($listener, false), ':'), 1)."\n";
flush();
$client = stream_socket_accept($listener, 10);
if ($client === false) { exit(2); }
while (($line = fgets($client)) !== false && trim($line) !== '') {}
fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nConnection: close\r\n\r\n");
fwrite($client, "data: {\"choices\":[{\"delta\":{\"content\":\"Первый\"}}]}\n\n");
fflush($client);
sleep(2);
fwrite($client, "data: [DONE]\n\n");
fflush($client);
fclose($client);
fclose($listener);
PHP;
        $process = proc_open([PHP_BINARY, '-r', $server], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        try {
            $port = trim((string) fgets($pipes[1]));
            $this->assertMatchesRegularExpression('/^\d+$/', $port);
            $provider = new RouterAiProvider(apiKey: 'test-key', baseUrl: "http://127.0.0.1:{$port}", model: 'openai/gpt-4o');
            $times = [];
            foreach ($provider->streamChat($this->textRequest(), new LLMCancellationToken(static fn (): bool => false)) as $event) {
                $times[$event->type] = microtime(true);
            }
            $this->assertArrayHasKey('delta', $times);
            $this->assertArrayHasKey('done', $times);
            $this->assertGreaterThan(1.2, $times['done'] - $times['delta']);
        } finally {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_terminate($process);
            proc_close($process);
        }
    }

    private function provider(\Closure $handler): RouterAiProvider
    {
        return new RouterAiProvider(
            apiKey: 'test-key',
            baseUrl: 'https://routerai.test/api/v1',
            model: 'openai/gpt-5.6-sol-pro',
            streamingClient: new Client(['handler' => HandlerStack::create($handler)]),
        );
    }

    private function textRequest(): LLMChatRequest
    {
        return new LLMChatRequest('system', [LLMChatMessage::text('user', 'Проверка')]);
    }

    private function pdfRequest(): LLMChatRequest
    {
        return new LLMChatRequest('system', [new LLMChatMessage('user', [new LLMFileContent('secret.pdf', 'application/pdf', 'SECRET', hash('sha256', 'SECRET'), LLMFileProcessingIntent::PDF_OCR)])]);
    }
}
