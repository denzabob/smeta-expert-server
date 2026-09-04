<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Application\Data\TrustedClassifierCandidateDescriptor;
use App\Domain\PriceIndices\Domain\Enums\ClassifierSemanticLevel;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierCandidateStagingException;

class TrustedClassifierCandidateRegistry
{
    public const OKPD2_145_2026 = 'okpd2_145_2026';

    public const OKPD2_148_2026 = 'okpd2_148_2026';

    public function get(string $candidateKey): TrustedClassifierCandidateDescriptor
    {
        return match (trim($candidateKey)) {
            self::OKPD2_145_2026 => $this->okpd2Version145(),
            self::OKPD2_148_2026 => $this->okpd2Version148(),
            default => throw new ClassifierCandidateStagingException(
                'classifier_candidate_not_supported',
                'The requested trusted classifier candidate is not registered.',
                'descriptor',
                ['candidate_key' => mb_substr(trim($candidateKey), 0, 128)],
            ),
        };
    }

    public function findMatchingCandidateKey(
        string $classifierCode,
        ?string $versionLabel,
        string $sourceSha256,
    ): ?string {
        foreach ([self::OKPD2_145_2026, self::OKPD2_148_2026] as $candidateKey) {
            $candidate = $this->get($candidateKey);

            if ($candidate->classifierCode === $classifierCode
                && $candidate->versionLabel === $versionLabel
                && hash_equals($candidate->sourceSha256, strtolower($sourceSha256))
            ) {
                return $candidateKey;
            }
        }

        return null;
    }

    private function okpd2Version145(): TrustedClassifierCandidateDescriptor
    {
        return new TrustedClassifierCandidateDescriptor(
            candidateKey: self::OKPD2_145_2026,
            classifierCode: 'okpd2',
            versionLabel: '145/2026',
            effectiveFrom: '2026-07-06',
            sourceSha256: '71a35241c4318c1ffbe4b47feb5c47ce34bd1ea24a6b58661acd289ea91fc46',
            parserCode: 'okpd2_rosstat_docx',
            parserVersion: 1,
            expectedSectionsCount: 21,
            expectedDigitalNodesCount: 20_961,
            expectedTotalNodesCount: 20_982,
            expectedNotesCount: 1_321,
            expectedWarningsCount: 0,
            expectedLevelCounts: [
                'class' => 88,
                'subclass' => 271,
                'group' => 619,
                'subgroup' => 1_463,
                'type' => 3_213,
                'category' => 8_401,
                'subcategory' => 6_906,
            ],
            controlNodeCode: '31.02.10.140',
            controlNodeName: 'Наборы кухонной мебели',
            controlNodeLevel: ClassifierSemanticLevel::Category,
            controlNodeParentCode: '31.02.10',
            controlAncestorParents: [
                'C' => null,
                '31' => 'C',
                '31.0' => '31',
                '31.02' => '31.0',
                '31.02.1' => '31.02',
                '31.02.10' => '31.02.1',
            ],
        );
    }

    private function okpd2Version148(): TrustedClassifierCandidateDescriptor
    {
        return new TrustedClassifierCandidateDescriptor(
            candidateKey: self::OKPD2_148_2026,
            classifierCode: 'okpd2',
            versionLabel: '148/2026',
            effectiveFrom: '2026-08-26',
            sourceSha256: '586ea967cda82eaee7651e0f9f920bcb1cb39db93901932be2d130869b39952c',
            parserCode: 'okpd2_rosstat_docx',
            parserVersion: 1,
            expectedSectionsCount: 21,
            expectedDigitalNodesCount: 21_595,
            expectedTotalNodesCount: 21_616,
            expectedNotesCount: 1_327,
            expectedWarningsCount: 0,
            expectedLevelCounts: [
                'class' => 88,
                'subclass' => 271,
                'group' => 619,
                'subgroup' => 1_463,
                'type' => 3_213,
                'category' => 8_587,
                'subcategory' => 7_354,
            ],
            controlNodeCode: '31.02.10.140',
            controlNodeName: 'Наборы кухонной мебели',
            controlNodeLevel: ClassifierSemanticLevel::Category,
            controlNodeParentCode: '31.02.10',
            controlAncestorParents: [
                'C' => null,
                '31' => 'C',
                '31.0' => '31',
                '31.02' => '31.0',
                '31.02.1' => '31.02',
                '31.02.10' => '31.02.1',
            ],
            expectedArtifactType: 'rar',
            expectedPartFilenames: ['OKPD2 01-35.docx', 'OKPD2 36-99.docx'],
        );
    }
}
