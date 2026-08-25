<?php

namespace App\Domain\PriceIndices\Application\Data;

use App\Domain\PriceIndices\Domain\Enums\ClassifierSemanticLevel;
use JsonException;

final readonly class TrustedClassifierCandidateDescriptor
{
    /**
     * @param  array<string, int>  $expectedLevelCounts
     * @param  array<string, string|null>  $controlAncestorParents
     */
    public function __construct(
        public string $candidateKey,
        public string $classifierCode,
        public string $versionLabel,
        public string $effectiveFrom,
        public string $sourceSha256,
        public string $parserCode,
        public int $parserVersion,
        public int $expectedSectionsCount,
        public int $expectedDigitalNodesCount,
        public int $expectedTotalNodesCount,
        public int $expectedNotesCount,
        public int $expectedWarningsCount,
        public array $expectedLevelCounts,
        public string $controlNodeCode,
        public string $controlNodeName,
        public ClassifierSemanticLevel $controlNodeLevel,
        public string $controlNodeParentCode,
        public array $controlAncestorParents,
    ) {}

    public function fingerprint(): string
    {
        try {
            $canonical = json_encode($this->fingerprintPayload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new \LogicException('Trusted classifier candidate fingerprint payload is not serializable.', previous: $exception);
        }

        return hash('sha256', $canonical);
    }

    public function genericParserProfile(): ClassifierExpectedProfile
    {
        return new ClassifierExpectedProfile(
            requiredSections: range('A', 'U'),
            minimumDigitalNodes: 10_000,
        );
    }

    /** @return array<string, mixed> */
    public function fingerprintPayload(): array
    {
        return [
            'candidate_key' => $this->candidateKey,
            'classifier_code' => $this->classifierCode,
            'version_label' => $this->versionLabel,
            'effective_from' => $this->effectiveFrom,
            'source_sha256' => $this->sourceSha256,
            'parser' => [
                'code' => $this->parserCode,
                'version' => $this->parserVersion,
            ],
            'expected_profile' => [
                'sections' => $this->expectedSectionsCount,
                'section_codes' => range('A', 'U'),
                'digital_nodes' => $this->expectedDigitalNodesCount,
                'total_nodes' => $this->expectedTotalNodesCount,
                'notes' => $this->expectedNotesCount,
                'warnings' => $this->expectedWarningsCount,
                'level_counts' => $this->expectedLevelCounts,
                'control_node' => [
                    'code' => $this->controlNodeCode,
                    'name' => $this->controlNodeName,
                    'semantic_level' => $this->controlNodeLevel->value,
                    'parent_code' => $this->controlNodeParentCode,
                    'ancestor_parents' => $this->controlAncestorParents,
                ],
            ],
        ];
    }
}
