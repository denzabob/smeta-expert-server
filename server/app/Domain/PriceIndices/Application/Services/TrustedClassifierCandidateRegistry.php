<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Application\Data\TrustedClassifierCandidateDescriptor;
use App\Domain\PriceIndices\Domain\Enums\ClassifierSemanticLevel;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierCandidateStagingException;

class TrustedClassifierCandidateRegistry
{
    public const OKPD2_145_2026 = 'okpd2_145_2026';

    public function get(string $candidateKey): TrustedClassifierCandidateDescriptor
    {
        return match (trim($candidateKey)) {
            self::OKPD2_145_2026 => $this->okpd2Version145(),
            default => throw new ClassifierCandidateStagingException(
                'classifier_candidate_not_supported',
                'The requested trusted classifier candidate is not registered.',
                'descriptor',
                ['candidate_key' => mb_substr(trim($candidateKey), 0, 128)],
            ),
        };
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
                '31' => null,
                '31.0' => '31',
                '31.02' => '31.0',
                '31.02.1' => '31.02',
                '31.02.10' => '31.02.1',
            ],
        );
    }
}
