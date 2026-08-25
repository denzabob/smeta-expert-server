<?php

namespace App\Domain\PriceIndices\Infrastructure\Parsing;

use App\Domain\PriceIndices\Domain\Exceptions\ClassifierParserException;

class ZipEntryNamePolicy
{
    public function canonical(string $name): string
    {
        $this->assertSafe($name);

        return strtolower(str_replace('\\', '/', $name));
    }

    public function assertSafe(string $name): void
    {
        $segments = preg_split('/[\\\\\/]+/', $name) ?: [];

        if ($name === ''
            || str_contains($name, "\0")
            || str_starts_with($name, '/')
            || str_starts_with($name, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $name) === 1
            || in_array('..', $segments, true)
        ) {
            throw ClassifierParserException::fatal(
                'unsafe_zip_entry_path',
                'The archive contains an unsafe entry path.'
            );
        }
    }
}
