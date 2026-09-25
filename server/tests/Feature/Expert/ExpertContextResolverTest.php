<?php

declare(strict_types=1);

namespace Tests\Feature\Expert;

use App\Models\Expert\ExpertConversation;
use App\Models\Expert\ExpertProject;
use App\Models\Expert\ExpertProjectMaterial;
use App\Models\User;
use App\Services\Expert\ExpertAttachmentBatch;
use App\Services\Expert\ExpertContextCandidate;
use App\Services\Expert\ExpertContextCandidatePool;
use App\Services\Expert\ExpertContextConstraints;
use App\Services\Expert\ExpertContextContinuityResolver;
use App\Services\Expert\ExpertContextDeterministicResolver;
use App\Services\Expert\ExpertContextResolutionValidator;
use App\Services\Expert\ExpertContextResolver;
use App\Services\Expert\ExpertConversationMaterialState;
use App\Services\Expert\ExpertRouterSemanticContextResolver;
use App\Services\Expert\ExpertSemanticContextResolver;
use App\Services\Expert\ExpertTaskIntent;
use App\Services\LLM\LLMSettingsRepository;
use App\Services\LLM\LLMTaskProfileResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;
use UnexpectedValueException;

final class ExpertContextResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_named_current_source_is_stable_with_noise_order_and_active_set(): void
    {
        $conversation = $this->conversation();
        $target = $this->material($conversation->project, 'Заключение Петрова.pdf');
        $noise = $this->material($conversation->project, 'random.jpg');
        $targetCandidate = $this->candidate($target, ['current']);
        $noiseCandidate = $this->candidate($noise, ['current']);
        $semantic = new FakeExpertSemanticResolver(new RuntimeException('Should not be called'));
        $query = 'Какой номер дела в Заключение Петрова.pdf?';
        $current = [$target->public_id, $noise->public_id];

        $first = $this->resolver($semantic)->resolve($conversation, $query, $this->intent(), $this->state(),
            new ExpertContextCandidatePool([$targetCandidate, $noiseCandidate], new ExpertAttachmentBatch(null, $current), false));

        $extra = [];
        $activeIds = [];
        foreach (range(1, 25) as $number) {
            $extension = $number <= 10 ? 'pdf' : ($number <= 20 ? 'jpg' : 'docx');
            $material = $this->material($conversation->project, "Unrelated {$number}.{$extension}");
            $extra[] = $this->candidate($material, ['active']);
            $activeIds[] = $material->public_id;
        }
        $second = $this->resolver($semantic)->resolve($conversation, $query, $this->intent(), $this->state($activeIds),
            new ExpertContextCandidatePool(array_reverse([...$extra, $noiseCandidate, $targetCandidate]), new ExpertAttachmentBatch(null, $current), false));

        $this->assertSame([$target->public_id], array_column($first->selected, 'material_id'));
        $this->assertSame($first->selected, $second->selected);
        $this->assertSame(0, $semantic->calls);
    }

    public function test_named_reference_uses_the_same_rule_for_different_names(): void
    {
        $conversation = $this->conversation();
        $materials = array_map(fn (string $name): ExpertProjectMaterial => $this->material($conversation->project,
            "Заключение {$name}.pdf"), ['Петрова', 'Иванова', 'Сидорова']);
        $pool = new ExpertContextCandidatePool(array_map(fn (ExpertProjectMaterial $material): ExpertContextCandidate => $this->candidate($material, ['project']), $materials), new ExpertAttachmentBatch(null, []), false);
        $semantic = new FakeExpertSemanticResolver(new RuntimeException('Should not be called'));

        foreach (['Петрова', 'Иванова', 'Сидорова'] as $index => $name) {
            $result = $this->resolver($semantic)->resolve($conversation, "Что в файле Заключение {$name}.pdf?",
                $this->intent(), $this->state(), $pool);
            $this->assertSame([$materials[$index]->public_id], array_column($result->selected, 'material_id'));
        }
        $this->assertSame(0, $semantic->calls);
    }

    public function test_short_follow_ups_keep_the_last_primary_without_semantic_routing(): void
    {
        $conversation = $this->conversation();
        $focused = $this->material($conversation->project, 'Заключение Дягилевой.pdf');
        $other = $this->material($conversation->project, 'Дополнительная экспертиза.pdf');
        $pool = new ExpertContextCandidatePool([
            $this->candidate($focused, ['last_primary']),
            $this->candidate($other, ['project']),
        ], new ExpertAttachmentBatch(null, []), false);
        $semantic = new FakeExpertSemanticResolver(new RuntimeException('Should not be called'));
        $state = $this->focusedState($focused->public_id);

        foreach ([
            'какой номер дела?', 'какая там дата?', 'кто указан экспертом?',
            'сколько вопросов?', 'почему?', 'а второй вопрос?',
            'какой номер дела в этой экспертизе?',
        ] as $query) {
            $result = $this->resolver($semantic)->resolve($conversation, $query, $this->intent(), $state, $pool);
            $this->assertSame([$focused->public_id], array_column($result->selected, 'material_id'), $query);
            $this->assertSame('conversational_focus', $result->selected[0]['reason_code'], $query);
            $this->assertFalse($result->semanticUsed, $query);
        }
        $this->assertSame(0, $semantic->calls);
    }

    public function test_exact_or_named_new_source_shifts_focus(): void
    {
        $conversation = $this->conversation();
        $focused = $this->material($conversation->project, 'Заключение Дягилевой.pdf');
        $named = $this->material($conversation->project, 'Заключение Петрова.pdf');
        $contract = $this->material($conversation->project, 'Договор аренды.pdf');
        $pool = new ExpertContextCandidatePool([
            $this->candidate($focused, ['last_primary']),
            $this->candidate($named, ['project']),
            $this->candidate($contract, ['project']),
        ], new ExpertAttachmentBatch(null, []), false);
        $semantic = new FakeExpertSemanticResolver(new RuntimeException('Should not be called'));
        $state = $this->focusedState($focused->public_id);

        foreach ([
            ['Что указано в Заключение Петрова.pdf?', $named->public_id],
            ['Что указал Петров?', $named->public_id],
            ['а что указал петров?', $named->public_id],
            ['Что написано в договоре?', $contract->public_id],
        ] as [$query, $expected]) {
            $result = $this->resolver($semantic)->resolve($conversation, $query, $this->intent(), $state, $pool);
            $this->assertSame([$expected], array_column($result->selected, 'material_id'), $query);
            $this->assertNotSame('conversational_focus', $result->selected[0]['reason_code'], $query);
        }
        $this->assertSame(0, $semantic->calls);
    }

    public function test_ambiguous_new_referent_does_not_fall_back_to_focus(): void
    {
        $conversation = $this->conversation();
        $focused = $this->material($conversation->project, 'Акт осмотра.pdf');
        $first = $this->material($conversation->project, 'Заключение 2025.pdf');
        $second = $this->material($conversation->project, 'Заключение 2026.pdf');
        $pool = new ExpertContextCandidatePool([
            $this->candidate($focused, ['last_primary']),
            $this->candidate($first, ['project']),
            $this->candidate($second, ['project']),
        ], new ExpertAttachmentBatch(null, []), false);
        $semantic = new FakeExpertSemanticResolver(new RuntimeException('Should not be called'));

        $result = $this->resolver($semantic)->resolve($conversation, 'А что в другом заключении?',
            $this->intent(), $this->focusedState($focused->public_id), $pool);

        $this->assertTrue($result->ambiguous);
        $this->assertSame([], $result->selected);
        $this->assertEqualsCanonicalizing([$first->public_id, $second->public_id], array_column($result->ambiguousCandidates, 'material_public_id'));
        $this->assertSame(0, $semantic->calls);
    }

    public function test_identity_routing_text_can_shift_focus_without_matching_filename(): void
    {
        $conversation = $this->conversation();
        $focused = $this->material($conversation->project, 'Заключение Дягилевой.pdf');
        $other = $this->material($conversation->project, 'Отчёт 2026.pdf');
        $otherCandidate = new ExpertContextCandidate(
            $other->public_id, $conversation->project->public_id, $other->original_name, $other->mime_type,
            $other->category, ['project'], null,
            ['aliases' => [$other->original_name], 'routing_text' => 'Эксперт Петров рассматривает объект'],
            'uploaded',
        );
        $pool = new ExpertContextCandidatePool([
            $this->candidate($focused, ['last_primary']), $otherCandidate,
        ], new ExpertAttachmentBatch(null, []), false);
        $semantic = new FakeExpertSemanticResolver(new RuntimeException('Should not be called'));

        $result = $this->resolver($semantic)->resolve($conversation, 'А что указал Петров?',
            $this->intent(), $this->focusedState($focused->public_id), $pool);

        $this->assertSame([$other->public_id], array_column($result->selected, 'material_id'));
        $this->assertSame('focus_shift', $result->selected[0]['reason_code']);
        $this->assertSame(0, $semantic->calls);
    }

    public function test_new_current_attachment_overrides_old_focus_and_last_batch_does_not_define_focus(): void
    {
        $conversation = $this->conversation();
        $focused = $this->material($conversation->project, 'Заключение Дягилевой.pdf');
        $other = $this->material($conversation->project, 'Дополнительная экспертиза.pdf');
        $images = array_map(fn (int $number): ExpertProjectMaterial => $this->material($conversation->project, "{$number}.jpg"), [1, 2, 3]);
        $lastBatch = [$images[0]->public_id, $images[1]->public_id, $images[2]->public_id, $other->public_id, $focused->public_id];
        $state = $this->focusedState($focused->public_id, $lastBatch);
        $candidates = [
            $this->candidate($focused, ['last_primary']),
            $this->candidate($other, ['historical']),
            ...array_map(fn (ExpertProjectMaterial $image): ExpertContextCandidate => $this->candidate($image, ['historical']), $images),
        ];
        $semantic = new FakeExpertSemanticResolver(new RuntimeException('Should not be called'));
        $resolver = $this->resolver($semantic);

        $followUp = $resolver->resolve($conversation, 'какой номер дела в этой экспертизе?', $this->intent(), $state,
            new ExpertContextCandidatePool($candidates, new ExpertAttachmentBatch(null, []), false));
        $newAttachment = $resolver->resolve($conversation, 'какой номер дела?', $this->intent(), $state,
            new ExpertContextCandidatePool([
                $this->candidate($focused, ['last_primary']), $this->candidate($other, ['current']),
            ], new ExpertAttachmentBatch(null, [$other->public_id]), false));

        $this->assertSame([$focused->public_id], array_column($followUp->selected, 'material_id'));
        $this->assertSame('conversational_focus', $followUp->selected[0]['reason_code']);
        $this->assertFalse($followUp->semanticUsed);
        $this->assertSame([$other->public_id], array_column($newAttachment->selected, 'material_id'));
        $this->assertSame(0, $semantic->calls);
    }

    public function test_semantic_can_exclude_unrelated_current_and_keeps_a_small_source_set(): void
    {
        $conversation = $this->conversation();
        $photo = $this->material($conversation->project, 'random.jpg');
        $report = $this->material($conversation->project, 'Заключение Петрова 2026.pdf');
        $semantic = new FakeExpertSemanticResolver($this->response([['material_id' => $report->public_id, 'role' => 'primary', 'reason_code' => 'semantic_identity_match']]));
        $pool = new ExpertContextCandidatePool([
            $this->candidate($photo, ['current']),
            $this->candidate($report, ['active']),
        ], new ExpertAttachmentBatch(null, [$photo->public_id]), false);

        $result = $this->resolver($semantic)->resolve($conversation,
            'Какие вопросы в заключении Петрова?', $this->intent(), $this->state([$report->public_id]), $pool);

        $this->assertSame([$report->public_id], array_column($result->selected, 'material_id'));
        $this->assertTrue($result->semanticUsed);
        $this->assertSame(1, $semantic->calls);
    }

    public function test_deictic_reference_to_active_reports_does_not_hard_include_unrelated_current_image(): void
    {
        $conversation = $this->conversation();
        $photo = $this->material($conversation->project, 'random.jpg');
        $first = $this->material($conversation->project, 'Экспертиза 2025.pdf');
        $second = $this->material($conversation->project, 'Экспертиза 2026.pdf');
        $semantic = new FakeExpertSemanticResolver($this->response([
            ['material_id' => $first->public_id, 'role' => 'primary', 'reason_code' => 'semantic_identity_match'],
            ['material_id' => $second->public_id, 'role' => 'comparison', 'reason_code' => 'semantic_identity_match'],
        ]));
        $pool = new ExpertContextCandidatePool([
            $this->candidate($photo, ['current']), $this->candidate($first, ['active']), $this->candidate($second, ['active']),
        ], new ExpertAttachmentBatch(null, [$photo->public_id]), false);

        $result = $this->resolver($semantic)->resolve($conversation, 'Что написано в этих экспертизах?',
            $this->intent(), $this->state([$first->public_id, $second->public_id]), $pool);

        $this->assertSame([$first->public_id, $second->public_id], array_column($result->selected, 'material_id'));
        $this->assertSame([], $result->hardIncludedIds);
    }

    public function test_ambiguity_and_semantic_outage_never_promote_all_candidates(): void
    {
        $conversation = $this->conversation();
        $first = $this->material($conversation->project, 'Заключение Петрова 2025.pdf');
        $second = $this->material($conversation->project, 'Заключение Петрова 2026.pdf');
        $pool = new ExpertContextCandidatePool([
            $this->candidate($first, ['active']), $this->candidate($second, ['active']),
        ], new ExpertAttachmentBatch(null, []), false);
        $state = $this->state([$first->public_id, $second->public_id]);
        $ambiguous = new FakeExpertSemanticResolver($this->response([], true, [$first->public_id, $second->public_id]));

        $result = $this->resolver($ambiguous)->resolve($conversation, 'Что написал Петров?', $this->intent(), $state, $pool);
        $outage = $this->resolver(new FakeExpertSemanticResolver(new RuntimeException('Provider unavailable')))
            ->resolve($conversation, 'Что написал Петров?', $this->intent(), $state, $pool);
        $prematureChoice = $this->resolver(new FakeExpertSemanticResolver($this->response([
            ['material_id' => $first->public_id, 'role' => 'primary', 'reason_code' => 'semantic_identity_match'],
        ])))->resolve($conversation, 'Что написал Петров?', $this->intent(), $state, $pool);

        $this->assertTrue($result->ambiguous);
        $this->assertSame([], $result->selected);
        $this->assertCount(2, $result->ambiguousCandidates);
        $this->assertTrue($outage->ambiguous);
        $this->assertSame([], $outage->selected);
        $this->assertTrue($prematureChoice->ambiguous);
        $this->assertCount(2, $prematureChoice->ambiguousCandidates);
    }

    public function test_named_exclusion_is_hard_and_unresolved_exclusion_fails_closed(): void
    {
        $conversation = $this->conversation();
        $first = $this->material($conversation->project, 'First.pdf');
        $second = $this->material($conversation->project, 'Second.pdf');
        $pool = new ExpertContextCandidatePool([
            $this->candidate($first, ['active']), $this->candidate($second, ['active']),
        ], new ExpertAttachmentBatch(null, []), false);
        $semantic = new FakeExpertSemanticResolver(new RuntimeException('Should not be called'));

        $resolved = $this->resolver($semantic)->resolve($conversation,
            'Сравни First.pdf кроме Second.pdf', $this->intent(), $this->state([$first->public_id, $second->public_id]), $pool);
        $unresolved = $this->resolver($semantic)->resolve($conversation,
            'Сравни First.pdf кроме Missing.pdf', $this->intent(), $this->state([$first->public_id, $second->public_id]), $pool);

        $this->assertSame([$first->public_id], array_column($resolved->selected, 'material_id'));
        $this->assertSame([$second->public_id], $resolved->hardExcludedIds);
        $this->assertTrue($unresolved->ambiguous);
        $this->assertSame([], $unresolved->selected);
        $this->assertSame(0, $semantic->calls);
    }

    public function test_project_exhaustive_is_a_separate_scope_without_semantic_selection(): void
    {
        $conversation = $this->conversation();
        $material = $this->material($conversation->project, 'Report.pdf');
        $semantic = new FakeExpertSemanticResolver(new RuntimeException('Should not be called'));
        $result = $this->resolver($semantic)->resolve($conversation,
            'Найди во всех материалах проекта упоминания',
            ExpertTaskIntent::fromArray(['task_type' => ExpertTaskIntent::FIND, 'coverage_mode' => ExpertTaskIntent::EXHAUSTIVE]),
            $this->state(), new ExpertContextCandidatePool([$this->candidate($material, ['project'])],
                new ExpertAttachmentBatch(null, []), false));

        $this->assertSame('project', $result->scope);
        $this->assertSame(ExpertTaskIntent::EXHAUSTIVE, $result->coverageMode);
        $this->assertSame([], $result->selected);
        $this->assertSame(0, $semantic->calls);
    }

    public function test_validator_rejects_unknown_foreign_duplicate_excluded_and_unknown_role(): void
    {
        $conversation = $this->conversation();
        $own = $this->material($conversation->project, 'Own.pdf');
        $foreign = $this->material($this->project($conversation->project->user_id), 'Foreign.pdf');
        $candidate = $this->candidate($own, ['active']);
        $validator = app(ExpertContextResolutionValidator::class);
        $valid = ['material_id' => $own->public_id, 'role' => 'primary', 'reason_code' => 'semantic_identity_match'];
        $invalid = [
            [$this->response([['material_id' => 'missing', 'role' => 'primary', 'reason_code' => 'semantic_identity_match']]), [$candidate], []],
            [$this->response([['material_id' => $foreign->public_id, 'role' => 'primary', 'reason_code' => 'semantic_identity_match']]), [$candidate, $this->candidate($foreign, ['project'])], []],
            [$this->response([$valid, $valid]), [$candidate], []],
            [$this->response([$valid]), [$candidate], [$own->public_id]],
            [$this->response([['material_id' => $own->public_id, 'role' => 'arbitrary', 'reason_code' => 'semantic_identity_match']]), [$candidate], []],
        ];
        foreach ($invalid as [$response, $candidates, $excluded]) {
            try {
                $validator->validate($response, $candidates,
                    new ExpertContextConstraints([], $excluded, false, false, []), $conversation);
                $this->fail('Invalid semantic selection was accepted.');
            } catch (UnexpectedValueException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_production_semantic_payload_uses_internal_profile_and_descriptors_only(): void
    {
        $conversation = $this->conversation();
        $conversation->messages()->create(['role' => 'assistant', 'content' => 'FALSE-ASSISTANT-EVIDENCE-991']);
        $material = $this->material($conversation->project, 'Заключение Петрова.pdf');
        $candidate = new ExpertContextCandidate($material->public_id, $conversation->project->public_id,
            $material->original_name, $material->mime_type, $material->category, ['project'], null,
            ['display_name' => $material->original_name, 'aliases' => ['Петрова'], 'state' => 'content_enriched',
                'routing_text' => str_repeat('ROUTING ', 200)], 'uploaded');
        app(LLMSettingsRepository::class)->saveFromAdmin([
            'mode' => 'manual', 'primary_provider' => 'routerai',
            'providers' => ['routerai' => ['api_key' => 'test-key', 'model' => 'custom/text-only',
                'base_url' => 'https://routerai.test/api/v1']],
        ]);
        app(LLMSettingsRepository::class)->saveTaskProfile(LLMTaskProfileResolver::EXPERT_CONTEXT_RESOLVER, [
            'provider' => 'routerai', 'model' => 'custom/text-only', 'enabled' => true,
            'fallback_policy' => 'none', 'temperature' => 0.9, 'max_output_tokens' => 4096,
        ]);
        Cache::forever('llm:routerai:model_catalog:v1', ['fetched_at' => time(), 'models' => [
            ['id' => 'custom/text-only', 'input_modalities' => ['text'], 'output_modalities' => ['text']],
        ]]);
        Http::fake(['*/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode(
            $this->response([['material_id' => $material->public_id, 'role' => 'primary', 'reason_code' => 'semantic_identity_match']]),
            JSON_THROW_ON_ERROR,
        )]]]], 200)]);

        $result = app(ExpertRouterSemanticContextResolver::class)->resolve($conversation, 'Что написал Петров?',
            $this->intent(), $this->focusedState($material->public_id), [$candidate], new ExpertContextConstraints([], [], false, false, []));

        $this->assertSame($material->public_id, $result['selected'][0]['material_id']);
        Http::assertSentCount(1);
        $request = Http::recorded()->first()[0]->data();
        $this->assertSame('custom/text-only', $request['model']);
        $this->assertSame(['type' => 'json_object'], $request['response_format']);
        $this->assertSame(0.0, $request['temperature']);
        $this->assertSame(500, $request['max_tokens']);
        $this->assertCount(2, $request['messages']);
        $routing = json_decode($request['messages'][1]['content'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($material->public_id, $routing['candidates'][0]['material_id']);
        $this->assertSame([$material->public_id], $routing['focused_source_ids']);
        $this->assertSame([$material->public_id], $routing['focused_primary_ids']);
        $this->assertStringContainsString('preserve the focused source set', $request['messages'][0]['content']);
        $this->assertLessThanOrEqual(800, mb_strlen($routing['candidates'][0]['routing_text']));
        $this->assertStringNotContainsString('FALSE-ASSISTANT-EVIDENCE-991', json_encode($request, JSON_THROW_ON_ERROR));
    }

    private function resolver(ExpertSemanticContextResolver $semantic): ExpertContextResolver
    {
        return new ExpertContextResolver(new ExpertContextDeterministicResolver, $semantic,
            app(ExpertContextResolutionValidator::class), new ExpertContextContinuityResolver);
    }

    private function state(array $active = []): ExpertConversationMaterialState
    {
        return new ExpertConversationMaterialState(null, [], [], [], [], $active, []);
    }

    /** @param list<string> $lastBatch */
    private function focusedState(string $id, array $lastBatch = []): ExpertConversationMaterialState
    {
        return new ExpertConversationMaterialState(
            $lastBatch === [] ? null : new ExpertAttachmentBatch('previous-message', $lastBatch),
            [$id], [$id], [], [], [], [], [$id], [$id], 'previous-message',
        );
    }

    private function intent(): ExpertTaskIntent
    {
        return ExpertTaskIntent::fromArray(['task_type' => ExpertTaskIntent::QUESTION_ANSWERING]);
    }

    private function candidate(ExpertProjectMaterial $material, array $origins): ExpertContextCandidate
    {
        return new ExpertContextCandidate($material->public_id, $material->project->public_id,
            $material->original_name, $material->mime_type, $material->category, $origins, null,
            ['aliases' => [$material->original_name, pathinfo($material->original_name, PATHINFO_FILENAME)],
                'state' => 'basic', 'routing_text' => null], $material->status);
    }

    private function response(array $selected, bool $ambiguous = false, array $ambiguousIds = []): array
    {
        return ['selected' => $selected, 'ambiguous' => $ambiguous,
            'ambiguous_candidates' => $ambiguousIds, 'confidence' => 0.93];
    }

    private function conversation(): ExpertConversation
    {
        $user = User::factory()->create();

        return $this->project($user->id)->conversations()->create(['title' => 'Resolver test']);
    }

    private function project(int $userId): ExpertProject
    {
        return ExpertProject::create(['user_id' => $userId, 'name' => 'Resolver project',
            'domain' => 'other', 'work_type' => 'other']);
    }

    private function material(ExpertProject $project, string $name): ExpertProjectMaterial
    {
        return $project->materials()->create(['uploaded_by' => $project->user_id,
            'original_name' => $name, 'storage_path' => 'expert/resolver/'.$name,
            'mime_type' => str_ends_with($name, '.jpg') ? 'image/jpeg'
                : (str_ends_with($name, '.docx') ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : 'application/pdf'),
            'extension' => pathinfo($name, PATHINFO_EXTENSION), 'size' => 1,
            'category' => 'research', 'status' => 'uploaded']);
    }
}

final class FakeExpertSemanticResolver implements ExpertSemanticContextResolver
{
    public int $calls = 0;

    public function __construct(private readonly array|RuntimeException $result) {}

    public function resolve(ExpertConversation $conversation, string $query, ExpertTaskIntent $intent,
        ExpertConversationMaterialState $state, array $candidates, ExpertContextConstraints $constraints): array
    {
        $this->calls++;
        if ($this->result instanceof RuntimeException) {
            throw $this->result;
        }

        return $this->result;
    }
}
