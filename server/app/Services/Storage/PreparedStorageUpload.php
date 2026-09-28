<?php

namespace App\Services\Storage;

final class PreparedStorageUpload
{
    public function __construct(
        public readonly int $userId,
        public readonly string $module,
        public readonly array $metadata,
        public readonly ?string $reservationId,
    ) {}

    public function path(): string
    {
        return $this->metadata['path'];
    }
}
