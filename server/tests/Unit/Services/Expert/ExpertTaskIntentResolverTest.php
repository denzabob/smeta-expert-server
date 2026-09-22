<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Expert;

use App\Services\Expert\ExpertSemanticTaskClassifier;
use App\Services\Expert\ExpertTaskIntent;
use App\Services\Expert\ExpertTaskIntentResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExpertTaskIntentResolverTest extends TestCase
{
    #[DataProvider('comparisonMessages')]
    public function test_different_comparison_formulations_have_one_intent(string $message): void
    {
        $intent = $this->resolver()->resolve($message, [
            'active_materials' => [
                ['id' => 'a', 'name' => 'Дополнительная экспертиза.pdf', 'mime_type' => 'application/pdf'],
                ['id' => 'b', 'name' => 'Заключение дягилевой.pdf', 'mime_type' => 'application/pdf'],
            ],
        ]);

        $this->assertSame(ExpertTaskIntent::COMPARE, $intent->taskType);
        $this->assertSame(ExpertTaskIntent::ACTIVE, $intent->materialScope);
        $this->assertTrue($intent->crossDocument);
        $this->assertSame(ExpertTaskIntent::FOCUSED, $intent->coverageMode);
        $this->assertTrue($intent->requiresReasoning);
    }

    public static function comparisonMessages(): array
    {
        return [
            ['сравни эти две экспертизы'],
            ['в чем отличаются эти заключения'],
            ['проведи сопоставительный анализ заключений'],
            ['какая экспертиза лучше структурирована'],
            ['проанализируй эти два документа'],
        ];
    }

    public function test_structural_current_images_have_priority_over_material_scope_interpretation(): void
    {
        $intent = $this->resolver()->resolve('какие вопросы стоят перед экспертом', [
            'current_materials' => [
                ['id' => '1', 'name' => '3.jpg', 'mime_type' => 'image/jpeg'],
                ['id' => '2', 'name' => '1.jpg', 'mime_type' => 'image/jpeg'],
                ['id' => '3', 'name' => '2.jpg', 'mime_type' => 'image/jpeg'],
            ],
        ]);

        $this->assertSame(ExpertTaskIntent::EXTRACT, $intent->taskType);
        $this->assertSame('expert_questions', $intent->target);
        $this->assertSame(ExpertTaskIntent::CURRENT, $intent->materialScope);
        $this->assertTrue($intent->requiresVisualReading);
        $this->assertSame(3, $intent->signals['structural']['current_material_count']);
    }

    public function test_find_word_is_not_comparison(): void
    {
        $intent = $this->resolver()->resolve("найди слово 'сравнение' в документе");

        $this->assertSame(ExpertTaskIntent::FIND, $intent->taskType);
        $this->assertFalse($intent->crossDocument);
    }

    public function test_find_all_normative_mentions_is_exhaustive_find(): void
    {
        $intent = $this->resolver()->resolve('найди все упоминания ГОСТ 16371', [
            'active_materials' => [
                ['id' => 'a', 'name' => 'A.pdf', 'mime_type' => 'application/pdf'],
                ['id' => 'b', 'name' => 'B.pdf', 'mime_type' => 'application/pdf'],
            ],
        ]);

        $this->assertSame(ExpertTaskIntent::FIND, $intent->taskType);
        $this->assertSame(ExpertTaskIntent::EXHAUSTIVE, $intent->coverageMode);
        $this->assertTrue($intent->requiresNormatives);
        $this->assertSame('normative_references', $intent->target);
    }

    public function test_active_normative_question_is_exhaustive_active_scope(): void
    {
        $intent = $this->resolver()->resolve('какие ГОСТы используются в этих документах?', [
            'active_materials' => [
                ['id' => 'a', 'name' => 'A.pdf', 'mime_type' => 'application/pdf'],
                ['id' => 'b', 'name' => 'B.pdf', 'mime_type' => 'application/pdf'],
                ['id' => 'c', 'name' => 'C.pdf', 'mime_type' => 'application/pdf'],
            ],
        ]);

        $this->assertSame(ExpertTaskIntent::ACTIVE, $intent->materialScope);
        $this->assertSame(ExpertTaskIntent::EXHAUSTIVE, $intent->coverageMode);
    }

    public function test_unknown_formulation_uses_safe_generic_fallback(): void
    {
        $intent = $this->resolver()->resolve('квантовый поворот синей линии');

        $this->assertSame(ExpertTaskIntent::GENERIC_ANALYSIS, $intent->taskType);
        $this->assertLessThan(0.5, $intent->confidence);
        $this->assertSame('deterministic', $intent->resolverSource);
    }

    public function test_selected_structural_materials_are_not_overridden_by_nlp_fallback(): void
    {
        $intent = $this->resolver()->resolve('необычная формулировка', [
            'selected_materials' => [
                ['id' => 'selected-1', 'name' => 'Выбранный.pdf', 'mime_type' => 'application/pdf'],
            ],
        ]);

        $this->assertSame(ExpertTaskIntent::SELECTED, $intent->materialScope);
        $this->assertSame(1, $intent->signals['structural']['selected_material_count']);
    }

    public function test_optional_classifier_can_supply_partial_semantic_hints(): void
    {
        $classifier = new class implements ExpertSemanticTaskClassifier
        {
            public function classify(string $message, array $structuralFacts, array $deterministicSignals): ?array
            {
                return [
                    'task_type' => ExpertTaskIntent::COMPARE,
                    'target' => 'writing_style',
                    'cross_document' => true,
                    'confidence' => 0.86,
                ];
            }
        };
        $intent = (new ExpertTaskIntentResolver($classifier))->resolve('неизвестная формулировка', [
            'active_material_count' => 2,
        ]);

        $this->assertSame(ExpertTaskIntent::COMPARE, $intent->taskType);
        $this->assertSame('writing_style', $intent->target);
        $this->assertTrue($intent->crossDocument);
        $this->assertSame('deterministic+semantic', $intent->resolverSource);
    }

    public function test_classifier_failure_does_not_break_deterministic_resolution(): void
    {
        $classifier = new class implements ExpertSemanticTaskClassifier
        {
            public function classify(string $message, array $structuralFacts, array $deterministicSignals): ?array
            {
                throw new \RuntimeException('classifier unavailable');
            }
        };
        $intent = (new ExpertTaskIntentResolver($classifier))->resolve('сравни эти два документа', [
            'active_material_count' => 2,
        ]);

        $this->assertSame(ExpertTaskIntent::COMPARE, $intent->taskType);
        $this->assertContains('semantic_classifier_unavailable', $intent->signals['hints']);
    }

    private function resolver(): ExpertTaskIntentResolver
    {
        return new ExpertTaskIntentResolver(new \App\Services\Expert\NullExpertSemanticTaskClassifier);
    }
}
