<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Expert;

use App\Services\Expert\ExpertAnalysisExecutionStrategy;
use App\Services\Expert\ExpertChatMaterialContext;
use App\Services\Expert\ExpertChatMaterialContextBundle;
use App\Services\Expert\ExpertContextPack;
use App\Services\Expert\ExpertMaterialProcessingLimits;
use App\Services\Expert\ExpertTaskRequirements;
use App\Services\Expert\ExpertWorkloadAssessor;
use Tests\TestCase;

final class ExpertWorkloadAssessorTest extends TestCase
{
    public function test_fifteen_small_materials_are_allowed_by_direct_budget(): void
    {
        $materials = array_map(
            static fn (int $index): array => [
                'public_id' => 'material-'.$index,
                'name' => 'Документ '.$index.'.txt',
                'mime_type' => 'text/plain',
                'text' => 'Короткий текст '.$index,
                'source_bytes' => 1024,
                'extracted_chars' => 18,
            ],
            range(1, 15),
        );
        $pack = $this->pack(array_column($materials, 'public_id'), 'targeted_multi', 'focused');
        $assessment = app(ExpertWorkloadAssessor::class)->assess(
            $pack,
            $this->requirements($pack),
            ExpertChatMaterialContextBundle::currentOnly(new ExpertChatMaterialContext($materials, [])),
        );

        $this->assertSame(ExpertAnalysisExecutionStrategy::DIRECT, $assessment->executionStrategy);
        $this->assertTrue($assessment->directContextAllowed);
        $this->assertSame(15, $assessment->materialCount);
    }

    public function test_retrieval_and_exhaustive_coverage_are_not_represented_as_direct(): void
    {
        $materials = [
            ['public_id' => 'a', 'name' => 'A.txt', 'mime_type' => 'text/plain', 'text' => 'A', 'source_bytes' => 100],
            ['public_id' => 'b', 'name' => 'B.txt', 'mime_type' => 'text/plain', 'text' => 'B', 'source_bytes' => 100],
        ];
        $bundle = ExpertChatMaterialContextBundle::currentOnly(new ExpertChatMaterialContext($materials, []));
        $assessor = app(ExpertWorkloadAssessor::class);

        $retrievalPack = $this->pack(['a', 'b'], 'retrieval_multi', 'focused');
        $retrieval = $assessor->assess($retrievalPack, $this->requirements($retrievalPack), $bundle);
        $this->assertSame(ExpertAnalysisExecutionStrategy::RETRIEVAL, $retrieval->executionStrategy);
        $this->assertTrue($retrieval->requiresRetrievalPipeline);

        $exhaustivePack = $this->pack(['a', 'b'], 'exhaustive_multi', 'exhaustive');
        $exhaustive = $assessor->assess($exhaustivePack, $this->requirements($exhaustivePack), $bundle);
        $this->assertSame(ExpertAnalysisExecutionStrategy::MULTI_DOCUMENT_EXHAUSTIVE, $exhaustive->executionStrategy);
        $this->assertFalse($exhaustive->directContextAllowed);
    }

    public function test_direct_budget_overflow_selects_multi_document_strategy(): void
    {
        config(['expert.analysis.direct.max_estimated_tokens' => 10]);
        $materials = [[
            'public_id' => 'large',
            'name' => 'large.txt',
            'mime_type' => 'text/plain',
            'text' => str_repeat('x', 100),
            'source_bytes' => 100,
        ]];
        $pack = $this->pack(['large'], 'single', 'focused');
        $assessment = app(ExpertWorkloadAssessor::class)->assess(
            $pack,
            $this->requirements($pack),
            ExpertChatMaterialContextBundle::currentOnly(new ExpertChatMaterialContext($materials, [])),
        );

        $this->assertSame(ExpertAnalysisExecutionStrategy::MULTI_DOCUMENT, $assessment->executionStrategy);
        $this->assertFalse($assessment->directContextAllowed);
        $this->assertSame('direct_context_budget_exceeded', $assessment->reason);
    }

    public function test_pdf_limits_are_separate_from_generic_material_limits(): void
    {
        config([
            'expert.material_context.max_material_bytes' => 5 * 1024 * 1024,
            'expert.pdf_ocr.max_source_bytes' => 20 * 1024 * 1024,
        ]);
        $resolver = app(ExpertMaterialProcessingLimits::class);

        $pdf = new \App\Models\Expert\ExpertProjectMaterial;
        $pdf->extension = 'pdf';
        $pdf->mime_type = 'application/pdf';
        $text = new \App\Models\Expert\ExpertProjectMaterial;
        $text->extension = 'txt';
        $text->mime_type = 'text/plain';

        $this->assertSame(20 * 1024 * 1024, $resolver->resolve($pdf)['max_source_bytes']);
        $this->assertSame(5 * 1024 * 1024, $resolver->resolve($text)['max_source_bytes']);
    }

    public function test_two_pdfs_over_ten_mib_are_not_rejected_by_generic_material_total(): void
    {
        $materials = [
            [
                'public_id' => 'pdf-a',
                'name' => 'Дополнительная экспертиза.pdf',
                'mime_type' => 'application/pdf',
                'text' => 'Первое заключение',
                'source_bytes' => 4_796_737,
                'page_count' => 1,
            ],
            [
                'public_id' => 'pdf-b',
                'name' => 'Заключение дягилевой.pdf',
                'mime_type' => 'application/pdf',
                'text' => 'Второе заключение',
                'source_bytes' => 9_538_885,
                'page_count' => 1,
            ],
        ];
        $pack = $this->pack(['pdf-a', 'pdf-b'], 'targeted_multi', 'focused');
        $assessment = app(ExpertWorkloadAssessor::class)->assess(
            $pack,
            $this->requirements($pack),
            ExpertChatMaterialContextBundle::currentOnly(new ExpertChatMaterialContext($materials, [])),
        );

        $this->assertSame(14_335_622, $assessment->sourceBytes);
        $this->assertSame(ExpertAnalysisExecutionStrategy::DIRECT, $assessment->executionStrategy);
        $this->assertTrue($assessment->directContextAllowed);
        $this->assertLessThan(100_000, $assessment->estimatedContextTokens);
    }

    public function test_image_workload_keeps_source_and_prepared_payload_separate(): void
    {
        $pack = $this->pack(['image'], 'single', 'focused');
        $image = new \App\Services\LLM\DTO\LLMImageContent('image', 'image.jpg', 'image/jpeg', str_repeat('p', 100), 10, 10, 1000);
        $bundle = ExpertChatMaterialContextBundle::currentOnly(new ExpertChatMaterialContext([], [$image]));

        $assessment = app(ExpertWorkloadAssessor::class)->assess($pack, $this->requirements($pack), $bundle);

        $this->assertSame(1000, $assessment->sourceBytes);
        $this->assertGreaterThan(100, $assessment->preparedPayloadBytes);
    }

    /** @param list<string> $ids */
    private function pack(array $ids, string $scope, string $coverage): ExpertContextPack
    {
        return new ExpertContextPack('', [], $ids, [], $ids, $scope, $coverage, false);
    }

    private function requirements(ExpertContextPack $pack): ExpertTaskRequirements
    {
        return new ExpertTaskRequirements(
            materialCount: count($pack->resolvedMaterials),
            currentMaterialCount: count($pack->currentMaterials),
            activeMaterialCount: count($pack->activeMaterials),
            hasImages: false,
            hasPdf: false,
            hasScannedPdf: false,
            hasSpreadsheet: false,
            scope: $pack->scope,
            coverageMode: $pack->coverageMode,
            requiresVision: false,
            requiresPdfProcessing: false,
            requiresMultiDocumentPipeline: false,
            requiresReasoning: false,
            requiresExhaustiveCoverage: $pack->coverageMode === 'exhaustive',
            hasExplicitComparison: false,
        );
    }
}
