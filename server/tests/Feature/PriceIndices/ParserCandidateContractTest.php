<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Application\Data\ParsedClassifierSnapshot;
use App\Domain\PriceIndices\Application\Data\TrustedClassifierCandidateDescriptor;
use App\Domain\PriceIndices\Application\Services\TrustedClassifierCandidateRegistry;
use App\Domain\PriceIndices\Application\Services\ValidateClassifierCandidateSnapshot;
use Tests\Feature\PriceIndices\Support\ClassifierParserTestCase;

class ParserCandidateContractTest extends ClassifierParserTestCase
{
    public function test_parser_control_hierarchy_satisfies_trusted_candidate_validator_contract(): void
    {
        $source = $this->storeSyntheticArtifact($this->makeSyntheticOkpd2Artifact());
        $snapshot = $this->parser()->parse($source, $this->syntheticExpectedProfile());
        $trusted = app(TrustedClassifierCandidateRegistry::class)->get('okpd2_145_2026');
        $nodesByCode = collect($snapshot->nodes)->keyBy('code');

        $actualAncestorParents = [];

        foreach (array_keys($trusted->controlAncestorParents) as $code) {
            $actualAncestorParents[$code] = $nodesByCode->get($code)?->parentCode;
        }

        $this->assertSame($trusted->controlAncestorParents, $actualAncestorParents);
        $this->assertSame($trusted->controlNodeParentCode, $nodesByCode->get($trusted->controlNodeCode)?->parentCode);

        $metrics = app(ValidateClassifierCandidateSnapshot::class)->validate(
            $this->descriptorForSyntheticSnapshot($trusted, $source->sha256, $snapshot),
            $source,
            $snapshot,
        );

        $this->assertSame($snapshot->totalNodesCount, $metrics['total_nodes_count']);
    }

    private function descriptorForSyntheticSnapshot(
        TrustedClassifierCandidateDescriptor $trusted,
        string $sourceSha256,
        ParsedClassifierSnapshot $snapshot,
    ): TrustedClassifierCandidateDescriptor {
        return new TrustedClassifierCandidateDescriptor(
            candidateKey: $trusted->candidateKey,
            classifierCode: $trusted->classifierCode,
            versionLabel: $trusted->versionLabel,
            effectiveFrom: $trusted->effectiveFrom,
            sourceSha256: $sourceSha256,
            parserCode: $trusted->parserCode,
            parserVersion: $trusted->parserVersion,
            expectedSectionsCount: $snapshot->sectionsCount,
            expectedDigitalNodesCount: $snapshot->digitalNodesCount,
            expectedTotalNodesCount: $snapshot->totalNodesCount,
            expectedNotesCount: $snapshot->validationSummary->metrics['notes_count'],
            expectedWarningsCount: count($snapshot->warnings),
            expectedLevelCounts: $snapshot->validationSummary->metrics['level_counts'],
            controlNodeCode: $trusted->controlNodeCode,
            controlNodeName: $trusted->controlNodeName,
            controlNodeLevel: $trusted->controlNodeLevel,
            controlNodeParentCode: $trusted->controlNodeParentCode,
            controlAncestorParents: $trusted->controlAncestorParents,
        );
    }
}
