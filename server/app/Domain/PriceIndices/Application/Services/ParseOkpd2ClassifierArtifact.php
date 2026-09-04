<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Application\Data\ClassifierExpectedProfile;
use App\Domain\PriceIndices\Application\Data\ClassifierParserIdentity;
use App\Domain\PriceIndices\Application\Data\ParsedClassifierSnapshot;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierSourceFile;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierAcquisitionException;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierParserException;
use App\Domain\PriceIndices\Infrastructure\Parsing\Okpd2RosstatDocxParser;
use App\Domain\PriceIndices\Infrastructure\Storage\ClassifierArtifactStorage;

class ParseOkpd2ClassifierArtifact
{
    public function __construct(
        private readonly ClassifierArtifactStorage $storage,
        private readonly Okpd2RosstatDocxParser $parser,
    ) {}

    public function identity(): ClassifierParserIdentity
    {
        return $this->parser->identity();
    }

    public function parse(
        StatisticalClassifierSourceFile $sourceArtifact,
        ?ClassifierExpectedProfile $expectedProfile = null,
        ?string $expectedArtifactType = null,
    ): ParsedClassifierSnapshot {
        try {
            $this->storage->verify(
                $sourceArtifact->storage_disk,
                $sourceArtifact->storage_path,
                $sourceArtifact->sha256,
                $sourceArtifact->size_bytes,
            );
            $absolutePath = $this->storage->absolutePath(
                $sourceArtifact->storage_disk,
                $sourceArtifact->storage_path,
            );
        } catch (ClassifierAcquisitionException $exception) {
            throw ClassifierParserException::fatal(
                'source_artifact_integrity_failure',
                'The stored classifier artifact failed immutable identity verification.',
                previous: $exception,
            );
        }

        $artifactType = $expectedArtifactType
            ?? ($sourceArtifact->metadata_json['artifact_type'] ?? null)
            ?? strtolower((string) pathinfo($sourceArtifact->storage_path, PATHINFO_EXTENSION));

        return $this->parser->parse($absolutePath, $expectedProfile, $artifactType !== '' ? $artifactType : null);
    }
}
