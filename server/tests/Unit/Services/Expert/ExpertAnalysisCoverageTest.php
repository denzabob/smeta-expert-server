<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Expert;

use App\Services\Expert\ExpertAnalysisCoverage;
use App\Services\Expert\ExpertChatMaterialContext;
use App\Services\Expert\ExpertChatMaterialContextBundle;
use App\Services\Expert\ExpertContextPack;
use App\Services\Expert\ExpertDocumentAnalysisResult;
use PHPUnit\Framework\TestCase;

final class ExpertAnalysisCoverageTest extends TestCase
{
    public function test_pending_material_keeps_coverage_incomplete_until_processed(): void
    {
        $pack = new ExpertContextPack('', [], [], [], ['pdf-1'], 'single', 'focused', false);
        $candidate = new \App\Services\Expert\ExpertPdfOcrCandidate(
            'project-1',
            'pdf-1',
            'заключение.pdf',
            'application/pdf',
            '%PDF',
            hash('sha256', '%PDF'),
            1,
        );
        $bundle = ExpertChatMaterialContextBundle::currentOnly(new ExpertChatMaterialContext([], [], [], [$candidate]));

        $coverage = ExpertAnalysisCoverage::fromBundle($pack, $bundle);
        $this->assertFalse($coverage->complete);
        $this->assertSame(0, $coverage->processed);
        $this->assertSame('processing', $coverage->materials[0]->status);

        $completed = $coverage->markAllProcessed();
        $this->assertTrue($completed->complete);
        $this->assertSame(['coverage_complete' => true], array_intersect_key($completed->toMetadata(), ['coverage_complete' => true]));
    }

    public function test_failed_material_is_never_reported_as_complete(): void
    {
        $pack = new ExpertContextPack('', [], [], [], ['a', 'b'], 'targeted_multi', 'focused', false);
        $materials = [
            ['public_id' => 'a', 'name' => 'a.txt', 'mime_type' => 'text/plain', 'text' => 'A'],
            ['public_id' => 'b', 'name' => 'b.txt', 'mime_type' => 'text/plain', 'text' => 'B'],
        ];
        $coverage = ExpertAnalysisCoverage::fromBundle($pack, ExpertChatMaterialContextBundle::currentOnly(new ExpertChatMaterialContext($materials, [])))
            ->markFailed('b', 'material_processing_failed');

        $this->assertFalse($coverage->complete);
        $this->assertSame(1, $coverage->processed);
        $this->assertSame(1, $coverage->failed);
        $this->assertSame('material_processing_failed', $coverage->materials[1]->errorCode);
    }

    public function test_document_result_contract_is_stable(): void
    {
        $result = new ExpertDocumentAnalysisResult('material-1', 'pdf', 'Кратко');

        $this->assertSame([
            'material_id' => 'material-1',
            'document_type' => 'pdf',
            'summary' => 'Кратко',
            'facts' => [],
            'claims' => [],
            'dates' => [],
            'persons' => [],
            'amounts' => [],
            'normative_references' => [],
            'conclusions' => [],
        ], $result->toArray());
    }
}
