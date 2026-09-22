<?php

declare(strict_types=1);

namespace Tests\Feature\Expert;

use App\Services\Expert\ExpertChatMaterialContext;
use App\Services\Expert\ExpertChatMaterialContextBundle;
use App\Services\Expert\ExpertContextPack;
use App\Services\Expert\ExpertTaskIntent;
use App\Services\Expert\ExpertTaskRequirementsResolver;
use Tests\TestCase;

final class ExpertTaskRequirementsIntentTest extends TestCase
{
    public function test_semantic_requirements_are_taken_from_intent_not_message_regex(): void
    {
        $intent = new ExpertTaskIntent(
            taskType: ExpertTaskIntent::COMPARE,
            target: 'writing_style',
            materialScope: ExpertTaskIntent::ACTIVE,
            coverageMode: ExpertTaskIntent::FOCUSED,
            crossDocument: true,
            requiresReasoning: true,
            requiresNormatives: false,
            requiresCalculation: false,
            requiresVisualReading: false,
            domain: 'generic',
            confidence: 0.92,
            resolverSource: 'deterministic',
            signals: ['hints' => ['comparison_hint']],
        );
        $pack = new ExpertContextPack('', [], ['a', 'b'], [], ['a', 'b'], 'targeted_multi', 'focused', false, intent: $intent);
        $bundle = ExpertChatMaterialContextBundle::currentOnly(new ExpertChatMaterialContext([
            ['public_id' => 'a', 'name' => 'A.pdf', 'mime_type' => 'application/pdf', 'text' => 'A'],
            ['public_id' => 'b', 'name' => 'B.pdf', 'mime_type' => 'application/pdf', 'text' => 'B'],
        ], []));

        $requirements = app(ExpertTaskRequirementsResolver::class)->resolve(
            $pack,
            "найди слово 'сравнение'",
            $bundle,
            $intent,
        );

        $this->assertTrue($requirements->hasExplicitComparison);
        $this->assertTrue($requirements->requiresReasoning);
        $this->assertFalse($requirements->requiresExhaustiveCoverage);
        $this->assertSame('compare', $requirements->toMetadata()['task_type']);
        $this->assertSame('writing_style', $requirements->toMetadata()['task_target']);
    }

    public function test_technical_image_fact_still_requires_vision(): void
    {
        $intent = new ExpertTaskIntent(
            taskType: ExpertTaskIntent::EXTRACT,
            target: 'expert_questions',
            materialScope: ExpertTaskIntent::CURRENT,
            coverageMode: ExpertTaskIntent::FOCUSED,
            crossDocument: false,
            requiresReasoning: false,
            requiresNormatives: false,
            requiresCalculation: false,
            requiresVisualReading: false,
            domain: 'generic',
            confidence: 0.9,
            resolverSource: 'deterministic',
        );
        $pack = new ExpertContextPack('', ['image'], [], [], ['image'], 'single', 'focused', false, intent: $intent);
        $image = new \App\Services\LLM\DTO\LLMImageContent('image', 'image.jpg', 'image/jpeg', 'bytes', 10, 10, 100);
        $bundle = ExpertChatMaterialContextBundle::currentOnly(new ExpertChatMaterialContext([], [$image]));

        $requirements = app(ExpertTaskRequirementsResolver::class)->resolve($pack, 'какие вопросы', $bundle, $intent);

        $this->assertTrue($requirements->hasImages);
        $this->assertTrue($requirements->requiresVision);
        $this->assertFalse($requirements->requiresReasoning);
    }
}
