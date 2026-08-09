<?php

namespace App\Domain\PriceIndices\Infrastructure\Storage;

use App\Domain\PriceIndices\Application\Data\StreamedFileHash;
use App\Domain\PriceIndices\Domain\Exceptions\SourceFileStorageException;

class StreamingFileHasher
{
    public function hash(string $absolutePath): StreamedFileHash
    {
        $stream = @fopen($absolutePath, 'rb');

        if ($stream === false) {
            throw new SourceFileStorageException('Unable to read the source file.');
        }

        try {
            $context = hash_init('sha256');
            $bytes = hash_update_stream($context, $stream);

            if ($bytes === false) {
                throw new SourceFileStorageException('Unable to hash the source file.');
            }

            return new StreamedFileHash(hash_final($context), $bytes);
        } finally {
            fclose($stream);
        }
    }
}
