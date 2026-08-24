<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Application\Contracts\ClassifierHttpTransport;
use App\Domain\PriceIndices\Application\Data\ClassifierHttpResponse;
use App\Domain\PriceIndices\Application\Data\DownloadedClassifierArtifact;
use App\Domain\PriceIndices\Application\Data\TrustedClassifierDescriptor;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierAcquisitionException;
use App\Domain\PriceIndices\Infrastructure\Http\ClassifierResponseMetadata;
use App\Domain\PriceIndices\Infrastructure\Http\ClassifierUrlPolicy;
use App\Domain\PriceIndices\Infrastructure\Storage\ClassifierArtifactStorage;
use Throwable;

class DownloadTrustedClassifierArtifact
{
    /** @var list<int> */
    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    public function __construct(
        private readonly ClassifierHttpTransport $transport,
        private readonly ClassifierUrlPolicy $urlPolicy,
        private readonly ClassifierResponseMetadata $metadata,
        private readonly ClassifierArtifactStorage $storage,
    ) {}

    public function download(TrustedClassifierDescriptor $descriptor): DownloadedClassifierArtifact
    {
        $url = $this->urlPolicy->validate($descriptor->downloadUrl, $descriptor->allowedHosts);
        $visited = [$url];
        $redirectCount = 0;

        while (true) {
            $response = $this->transport->get($url, $descriptor);

            if (in_array($response->status, self::REDIRECT_STATUSES, true)) {
                try {
                    $url = $this->urlPolicy->resolveRedirect(
                        $url,
                        $this->metadata->location($response),
                        $descriptor->allowedHosts,
                        $visited,
                        $redirectCount,
                        $descriptor->maxRedirects,
                    );
                } finally {
                    $response->close();
                }

                $visited[] = $url;
                $redirectCount++;

                continue;
            }

            if ($response->status !== 200) {
                $response->close();

                throw new ClassifierAcquisitionException(
                    'unexpected_http_status',
                    "Classifier download returned unexpected HTTP status [{$response->status}]."
                );
            }

            return $this->streamSuccessfulResponse($descriptor, $url, $response);
        }
    }

    private function streamSuccessfulResponse(
        TrustedClassifierDescriptor $descriptor,
        string $resolvedUrl,
        ClassifierHttpResponse $response,
    ): DownloadedClassifierArtifact {
        $temporaryPath = '';
        $output = null;

        try {
            $mimeType = $this->metadata->mimeType($response);

            if (! in_array($mimeType, $descriptor->allowedMimeTypes, true)) {
                throw new ClassifierAcquisitionException('invalid_mime_type', "Classifier MIME type [{$mimeType}] is not allowed.");
            }

            $contentLength = $this->metadata->contentLength($response);

            if ($contentLength !== null && $contentLength > $descriptor->maxSizeBytes) {
                throw new ClassifierAcquisitionException('artifact_too_large', 'Classifier artifact exceeds the configured size limit.');
            }

            $etag = $this->metadata->etag($response);
            $lastModifiedAt = $this->metadata->lastModified($response);
            $temporaryPath = $this->storage->createTemporaryPath($descriptor);
            $output = @fopen($this->storage->absolutePath($descriptor->storageDisk, $temporaryPath), 'wb');

            if ($output === false) {
                throw new ClassifierAcquisitionException('storage_failure', 'Unable to open classifier temporary file.');
            }

            $hash = hash_init('sha256');
            $size = 0;
            $magic = '';

            while (! $response->body->eof()) {
                $chunk = $response->body->read(65_536);

                if ($chunk === '') {
                    if ($response->body->eof()) {
                        break;
                    }

                    throw new ClassifierAcquisitionException('partial_download', 'Classifier response stream ended unexpectedly.');
                }

                $size += strlen($chunk);

                if ($size > $descriptor->maxSizeBytes) {
                    throw new ClassifierAcquisitionException('artifact_too_large', 'Classifier artifact exceeds the configured size limit.');
                }

                if (strlen($magic) < 4) {
                    $magic .= substr($chunk, 0, 4 - strlen($magic));
                }

                hash_update($hash, $chunk);
                $this->writeAll($output, $chunk);
            }

            if ($size === 0) {
                throw new ClassifierAcquisitionException('empty_artifact', 'Classifier artifact is empty.');
            }

            if ($contentLength !== null && $contentLength !== $size) {
                throw new ClassifierAcquisitionException('partial_download', 'Classifier bytes do not match Content-Length.');
            }

            if (! in_array($magic, ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true)) {
                throw new ClassifierAcquisitionException('invalid_zip_signature', 'Classifier artifact does not have a valid ZIP signature.');
            }

            fclose($output);
            $output = null;

            return new DownloadedClassifierArtifact(
                temporaryPath: $temporaryPath,
                resolvedUrl: $resolvedUrl,
                mimeType: $mimeType,
                sizeBytes: $size,
                sha256: hash_final($hash),
                etag: $etag,
                lastModifiedAt: $lastModifiedAt,
            );
        } catch (ClassifierAcquisitionException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ClassifierAcquisitionException(
                'transport_failure',
                'Classifier response stream failed before completion.',
                $exception,
            );
        } finally {
            if (is_resource($output)) {
                fclose($output);
            }

            $response->close();

            if (isset($exception) && $temporaryPath !== '') {
                $this->storage->deleteTemporary($descriptor->storageDisk, $temporaryPath);
            }
        }
    }

    /** @param resource $output */
    private function writeAll($output, string $chunk): void
    {
        $offset = 0;
        $length = strlen($chunk);

        while ($offset < $length) {
            $written = fwrite($output, substr($chunk, $offset));

            if ($written === false || $written === 0) {
                throw new ClassifierAcquisitionException('storage_failure', 'Unable to write classifier temporary file.');
            }

            $offset += $written;
        }
    }
}
