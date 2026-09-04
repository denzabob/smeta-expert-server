<?php

namespace App\Domain\PriceIndices\Infrastructure\Storage;

use App\Domain\PriceIndices\Application\Data\TrustedClassifierDescriptor;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierAcquisitionException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ClassifierArtifactStorage
{
    public function __construct(private readonly StreamingFileHasher $hasher) {}

    public function createTemporaryPath(TrustedClassifierDescriptor $descriptor): string
    {
        $directory = trim($descriptor->tempDirectory, '/');
        $path = $directory.'/'.Str::uuid().'.part';
        $disk = $this->disk($descriptor->storageDisk);

        $disk->makeDirectory($directory);
        $stream = @fopen($disk->path($path), 'x+b');

        if ($stream === false) {
            throw new ClassifierAcquisitionException('storage_failure', 'Unable to create classifier temporary file.');
        }

        fclose($stream);

        return $path;
    }

    public function absolutePath(string $diskName, string $path): string
    {
        return $this->disk($diskName)->path($path);
    }

    public function finalPath(TrustedClassifierDescriptor $descriptor, string $sha256): string
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $descriptor->code) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1
            || ! in_array($descriptor->artifactType, ['zip', 'rar'], true)
        ) {
            throw new ClassifierAcquisitionException('storage_failure', 'Classifier artifact identity is unsafe for storage.');
        }

        return "classifiers/{$descriptor->code}/artifacts/{$sha256}.{$descriptor->artifactType}";
    }

    public function finalize(
        TrustedClassifierDescriptor $descriptor,
        string $temporaryPath,
        string $sha256,
        int $sizeBytes,
    ): string {
        $disk = $this->disk($descriptor->storageDisk);
        $finalPath = $this->finalPath($descriptor, $sha256);
        $temporaryAbsolutePath = $disk->path($temporaryPath);
        $finalAbsolutePath = $disk->path($finalPath);

        $this->verifyAbsolute($temporaryAbsolutePath, $sha256, $sizeBytes, 'temporary artifact');

        if ($disk->exists($finalPath)) {
            $this->verifyAbsolute($finalAbsolutePath, $sha256, $sizeBytes, 'existing artifact');
            $this->deleteTemporary($descriptor->storageDisk, $temporaryPath);

            return $finalPath;
        }

        $disk->makeDirectory(dirname($finalPath));

        if (@link($temporaryAbsolutePath, $finalAbsolutePath)) {
            if (! @unlink($temporaryAbsolutePath)) {
                throw new ClassifierAcquisitionException('storage_failure', 'Unable to remove linked classifier temporary file.');
            }

            $this->verifyAbsolute($finalAbsolutePath, $sha256, $sizeBytes, 'finalized artifact');

            return $finalPath;
        }

        if ($disk->exists($finalPath)) {
            $this->verifyAbsolute($finalAbsolutePath, $sha256, $sizeBytes, 'concurrently finalized artifact');
            $this->deleteTemporary($descriptor->storageDisk, $temporaryPath);

            return $finalPath;
        }

        throw new ClassifierAcquisitionException('storage_failure', 'Atomic no-clobber classifier artifact finalization failed.');
    }

    public function verify(
        string $diskName,
        string $path,
        string $sha256,
        int $sizeBytes,
    ): void {
        $disk = $this->disk($diskName);

        if (! $disk->exists($path)) {
            throw new ClassifierAcquisitionException('storage_integrity_failure', 'Classifier artifact is missing.');
        }

        $this->verifyAbsolute($disk->path($path), $sha256, $sizeBytes, 'stored artifact');
    }

    public function deleteTemporary(string $diskName, string $path): void
    {
        if ($path === '') {
            return;
        }

        $disk = $this->disk($diskName);

        try {
            if ($disk->exists($path)) {
                $disk->delete($path);
            }
        } catch (Throwable $exception) {
            throw new ClassifierAcquisitionException(
                'storage_failure',
                'Unable to clean up classifier temporary file.',
                $exception,
            );
        }
    }

    private function verifyAbsolute(string $absolutePath, string $sha256, int $sizeBytes, string $label): void
    {
        try {
            $hash = $this->hasher->hash($absolutePath);
        } catch (Throwable $exception) {
            throw new ClassifierAcquisitionException(
                'storage_integrity_failure',
                "Unable to verify {$label}.",
                $exception,
            );
        }

        if ($hash->size !== $sizeBytes || ! hash_equals($sha256, $hash->sha256)) {
            throw new ClassifierAcquisitionException(
                'storage_integrity_failure',
                "Classifier {$label} size or SHA-256 does not match its immutable identity."
            );
        }
    }

    private function disk(string $diskName): FilesystemAdapter
    {
        return Storage::disk($diskName);
    }
}
