<?php

namespace App\Domain\PriceIndices\Application\Data;

final readonly class TrustedClassifierDescriptor
{
    /**
     * @param  list<string>  $allowedHosts
     * @param  list<string>  $allowedMimeTypes
     */
    public function __construct(
        public string $code,
        public string $standardCode,
        public string $name,
        public string $issuingAuthority,
        public string $officialDistributor,
        public string $sourcePageUrl,
        public string $downloadUrl,
        public array $allowedHosts,
        public string $artifactType,
        public string $originalFilename,
        public array $allowedMimeTypes,
        public int $maxSizeBytes,
        public int $timeoutSeconds,
        public int $connectTimeoutSeconds,
        public int $maxRedirects,
        public string $storageDisk,
        public string $tempDirectory,
    ) {}

    /** @return array<string, string> */
    public function classifierIdentity(): array
    {
        return [
            'standard_code' => $this->standardCode,
            'name' => $this->name,
            'issuing_authority' => $this->issuingAuthority,
            'official_distributor' => $this->officialDistributor,
        ];
    }
}
