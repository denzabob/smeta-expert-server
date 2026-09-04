<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Application\Data\TrustedClassifierDescriptor;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierAcquisitionException;

class TrustedClassifierDescriptorRegistry
{
    public function get(string $code): TrustedClassifierDescriptor
    {
        $normalizedCode = strtolower(trim($code));
        $descriptor = config("price_indices.classifier_acquisition.descriptors.{$normalizedCode}");

        if (! is_array($descriptor)) {
            throw new ClassifierAcquisitionException(
                'classifier_not_supported',
                "Classifier [{$code}] is not supported for trusted acquisition."
            );
        }

        $config = (array) config('price_indices.classifier_acquisition', []);

        return new TrustedClassifierDescriptor(
            code: $this->requiredString($descriptor, 'code'),
            standardCode: $this->requiredString($descriptor, 'standard_code'),
            name: $this->requiredString($descriptor, 'name'),
            issuingAuthority: $this->requiredString($descriptor, 'issuing_authority'),
            officialDistributor: $this->requiredString($descriptor, 'official_distributor'),
            sourcePageUrl: $this->requiredString($descriptor, 'source_page_url'),
            downloadUrl: $this->optionalString($descriptor, 'download_url'),
            allowedHosts: $this->requiredStringList($descriptor, 'allowed_hosts'),
            artifactType: $this->requiredString($descriptor, 'artifact_type'),
            originalFilename: $this->requiredString($descriptor, 'original_filename'),
            allowedMimeTypes: $this->requiredStringList($descriptor, 'allowed_mime_types'),
            maxSizeBytes: $this->positiveInteger($config, 'max_size_bytes'),
            timeoutSeconds: $this->positiveInteger($config, 'timeout_seconds'),
            connectTimeoutSeconds: $this->positiveInteger($config, 'connect_timeout_seconds'),
            maxRedirects: $this->nonNegativeInteger($config, 'max_redirects'),
            storageDisk: $this->requiredString($config, 'storage_disk'),
            tempDirectory: $this->requiredString($config, 'temp_directory'),
        );
    }

    /** @param array<string, mixed> $values */
    private function optionalString(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || trim($value) === '') {
            $this->invalidConfiguration($key);
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private function requiredString(array $values, string $key): string
    {
        $value = $values[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            $this->invalidConfiguration($key);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    private function requiredStringList(array $values, string $key): array
    {
        $value = $values[$key] ?? null;

        if (! is_array($value) || $value === [] || array_filter($value, fn (mixed $item): bool => ! is_string($item) || $item === '') !== []) {
            $this->invalidConfiguration($key);
        }

        return array_values($value);
    }

    /** @param array<string, mixed> $values */
    private function positiveInteger(array $values, string $key): int
    {
        $value = $values[$key] ?? null;

        if (! is_int($value) || $value < 1) {
            $this->invalidConfiguration($key);
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private function nonNegativeInteger(array $values, string $key): int
    {
        $value = $values[$key] ?? null;

        if (! is_int($value) || $value < 0) {
            $this->invalidConfiguration($key);
        }

        return $value;
    }

    private function invalidConfiguration(string $key): never
    {
        throw new ClassifierAcquisitionException(
            'invalid_trusted_descriptor',
            "Trusted classifier acquisition configuration [{$key}] is invalid."
        );
    }
}
