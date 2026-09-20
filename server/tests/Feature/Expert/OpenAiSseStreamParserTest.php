<?php

declare(strict_types=1);

namespace Tests\Feature\Expert;

use App\Services\LLM\DTO\LLMCancellationToken;
use App\Services\LLM\Exceptions\LLMProviderException;
use App\Services\LLM\Parsing\OpenAiSseStreamParser;
use Tests\TestCase;

final class OpenAiSseStreamParserTest extends TestCase
{
    public function test_fragmented_openai_sse_keeps_incremental_text_and_metadata(): void
    {
        $frames = [
            'data: {"id":"upstream-1","model":"upstream/actual-model","choices":[{"delta":{"content":"Это "}}]}', "\n\n",
            "data: {\"choices\":[{\"delta\":{\"content\":\"поток\"}}]}\n\ndata: {\"usage\":{\"prompt_tokens\":7,\"completion_tokens\":2,\"total_tokens\":9}}\n\n",
            'data: [DO', "NE]\n\n",
        ];
        $events = iterator_to_array((new OpenAiSseStreamParser)->parse($frames, new LLMCancellationToken(static fn (): bool => false)));

        $this->assertSame('Это ', $events[0]->text);
        $this->assertSame('поток', $events[1]->text);
        $this->assertSame('done', $events[2]->type);
        $this->assertSame('upstream-1', $events[2]->metadata['upstream_id']);
        $this->assertSame('upstream/actual-model', $events[2]->metadata['model']);
        $this->assertSame(9, $events[2]->metadata['total_tokens']);
    }

    public function test_only_explicit_safe_summary_field_is_emitted_and_generic_reasoning_is_discarded(): void
    {
        $frames = [
            'data: {"choices":[{"delta":{"reasoning":"RAW-CHAIN-OF-THOUGHT","reasoning_details":"RAW-DETAILS","reasoning_summary":"Проверены материалы."}}]}', "\n\n",
            'data: {"choices":[{"message":{"reasoning_summary":"Итог анализа.","reasoning":"RAW-FINAL"}}]}', "\n\n",
            "data: [DONE]\n\n",
        ];

        $events = iterator_to_array((new OpenAiSseStreamParser(true))->parse($frames, new LLMCancellationToken(static fn (): bool => false)));

        $this->assertSame('reasoning_summary', $events[0]->type);
        $this->assertSame('Проверены материалы.', $events[0]->text);
        $this->assertFalse($events[0]->isFinal);
        $this->assertSame('Итог анализа.', $events[1]->text);
        $this->assertTrue($events[1]->isFinal);
        $this->assertStringNotContainsString('RAW-', json_encode($events, JSON_THROW_ON_ERROR));
    }

    public function test_eof_without_done_is_a_provider_failure_and_never_emits_done(): void
    {
        $events = [];
        try {
            foreach ((new OpenAiSseStreamParser)->parse([
                'data: {"choices":[{"delta":{"content":"Частичный ответ"}}]}', "\n\n",
            ], new LLMCancellationToken(static fn (): bool => false)) as $event) {
                $events[] = $event;
            }
            $this->fail('EOF without [DONE] must fail the provider stream.');
        } catch (LLMProviderException $exception) {
            $this->assertSame('stream_eof_without_terminal', $exception->getErrorType());
            $this->assertSame(['delta'], array_map(static fn ($event): string => $event->type, $events));
            $this->assertNotContains('done', array_map(static fn ($event): string => $event->type, $events));
        }
    }

    public function test_malformed_json_frame_fails_without_exposing_the_frame(): void
    {
        try {
            iterator_to_array((new OpenAiSseStreamParser)->parse(["data: {PRIVATE-DOCUMENT}\n\n"], new LLMCancellationToken(static fn (): bool => false)));
            $this->fail('Malformed frame must fail.');
        } catch (LLMProviderException $exception) {
            $this->assertSame('stream_malformed', $exception->getErrorType());
            $this->assertStringNotContainsString('PRIVATE-DOCUMENT', $exception->getMessage());
        }
    }

    public function test_dedicated_summary_is_ignored_until_the_provider_capability_is_enabled(): void
    {
        $events = iterator_to_array((new OpenAiSseStreamParser)->parse([
            'data: {"choices":[{"delta":{"reasoning_summary":"Не должен быть показан."}}]}', "\n\n",
            "data: [DONE]\n\n",
        ], new LLMCancellationToken(static fn (): bool => false)));

        $this->assertSame(['done'], array_map(static fn ($event): string => $event->type, $events));
    }
}
