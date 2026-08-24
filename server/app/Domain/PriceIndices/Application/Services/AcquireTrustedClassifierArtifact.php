<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Application\Data\ClassifierAcquisitionResult;
use App\Domain\PriceIndices\Application\Data\DownloadedClassifierArtifact;
use App\Domain\PriceIndices\Application\Data\TrustedClassifierDescriptor;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierSourceFile;
use App\Domain\PriceIndices\Domain\Enums\ClassifierSourceTrustTier;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierAcquisitionException;
use App\Domain\PriceIndices\Infrastructure\Persistence\ClassifierSourceFileRepository;
use App\Domain\PriceIndices\Infrastructure\Storage\ClassifierArtifactStorage;
use Illuminate\Database\QueryException;

class AcquireTrustedClassifierArtifact
{
    public function __construct(
        private readonly TrustedClassifierDescriptorRegistry $descriptors,
        private readonly ResolveTrustedStatisticalClassifier $classifiers,
        private readonly DownloadTrustedClassifierArtifact $downloader,
        private readonly ClassifierArtifactStorage $storage,
        private readonly ClassifierSourceFileRepository $sourceFiles,
    ) {}

    public function acquire(string $classifierCode): ClassifierAcquisitionResult
    {
        $descriptor = $this->descriptors->get($classifierCode);
        $classifier = $this->classifiers->resolve($descriptor);
        $download = $this->downloader->download($descriptor);

        try {
            $existing = $this->sourceFiles->findByClassifierAndHash($classifier->id, $download->sha256);

            if ($existing !== null) {
                $this->verifyExistingRow($descriptor, $existing, $download);

                return new ClassifierAcquisitionResult($classifier, $existing, $download->resolvedUrl, true);
            }

            $storagePath = $this->storage->finalize(
                $descriptor,
                $download->temporaryPath,
                $download->sha256,
                $download->sizeBytes,
            );

            try {
                $sourceFile = $this->sourceFiles->create([
                    'classifier_id' => $classifier->id,
                    'trust_tier' => ClassifierSourceTrustTier::OfficialAuthoritative,
                    'source_page_url' => $descriptor->sourcePageUrl,
                    'download_url' => $descriptor->downloadUrl,
                    'resolved_url' => $download->resolvedUrl,
                    'original_filename' => $descriptor->originalFilename,
                    'storage_disk' => $descriptor->storageDisk,
                    'storage_path' => $storagePath,
                    'mime_type' => $download->mimeType,
                    'size_bytes' => $download->sizeBytes,
                    'sha256' => $download->sha256,
                    'etag' => $download->etag,
                    'last_modified_at' => $download->lastModifiedAt,
                    'downloaded_at' => now(),
                    'declared_version_label' => null,
                    'metadata_json' => null,
                ]);
            } catch (QueryException $exception) {
                if (! $this->isExpectedDuplicateRace($exception)) {
                    throw $exception;
                }

                $sourceFile = $this->sourceFiles->findByClassifierAndHash($classifier->id, $download->sha256);

                if ($sourceFile === null) {
                    throw $exception;
                }

                $this->verifyExistingRow($descriptor, $sourceFile, $download);

                return new ClassifierAcquisitionResult($classifier, $sourceFile, $download->resolvedUrl, true);
            }

            return new ClassifierAcquisitionResult($classifier, $sourceFile, $download->resolvedUrl, false);
        } finally {
            $this->storage->deleteTemporary($descriptor->storageDisk, $download->temporaryPath);
        }
    }

    private function verifyExistingRow(
        TrustedClassifierDescriptor $descriptor,
        StatisticalClassifierSourceFile $sourceFile,
        DownloadedClassifierArtifact $download,
    ): void {
        $expectedPath = $this->storage->finalPath($descriptor, $download->sha256);

        if ($sourceFile->storage_disk !== $descriptor->storageDisk
            || $sourceFile->storage_path !== $expectedPath
            || $sourceFile->size_bytes !== $download->sizeBytes
            || ! hash_equals($download->sha256, $sourceFile->sha256)
        ) {
            throw new ClassifierAcquisitionException(
                'storage_integrity_failure',
                'Existing classifier source row does not match its content-addressed artifact identity.'
            );
        }

        $this->storage->verify(
            $sourceFile->storage_disk,
            $sourceFile->storage_path,
            $sourceFile->sha256,
            $sourceFile->size_bytes,
        );
    }

    private function isExpectedDuplicateRace(QueryException $exception): bool
    {
        return ($exception->errorInfo[1] ?? null) === 1062
            && str_contains($exception->getMessage(), 'stat_cls_src_classifier_sha_unique');
    }
}
