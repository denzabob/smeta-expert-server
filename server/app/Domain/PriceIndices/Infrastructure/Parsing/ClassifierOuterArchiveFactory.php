<?php

namespace App\Domain\PriceIndices\Infrastructure\Parsing;

use App\Domain\PriceIndices\Application\Data\ZipSafetyLimits;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierParserException;

class ClassifierOuterArchiveFactory
{
    public function __construct(
        private readonly SafeZipArchiveInspector $zip,
        private readonly RarClassifierArchiveInspector $rar,
    ) {}

    /** @param array<string, mixed> $rarConfiguration */
    public function open(
        string $absolutePath,
        ?string $expectedType,
        ZipSafetyLimits $zipLimits,
        array $rarConfiguration,
    ): ClassifierOuterArchive {
        $type = $expectedType ?? $this->detectType($absolutePath);

        return match ($type) {
            'zip' => $this->zip->open($absolutePath, $zipLimits),
            'rar' => $this->rar->open($absolutePath, $zipLimits, $rarConfiguration),
            default => throw ClassifierParserException::fatal(
                'unsupported_outer_archive_type',
                'The classifier outer archive type is not supported by the trusted parser.',
            ),
        };
    }

    public function detectType(string $absolutePath): ?string
    {
        $handle = @fopen($absolutePath, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            $signature = fread($handle, 8);
        } finally {
            fclose($handle);
        }

        if (is_string($signature) && in_array(substr($signature, 0, 4), ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true)) {
            return 'zip';
        }

        if ($signature === "Rar!\x1a\x07\x01\x00") {
            return 'rar';
        }

        return null;
    }
}
