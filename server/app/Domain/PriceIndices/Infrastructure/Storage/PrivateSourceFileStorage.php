<?php

namespace App\Domain\PriceIndices\Infrastructure\Storage;

use App\Domain\PriceIndices\Domain\Exceptions\SourceFileStorageException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PrivateSourceFileStorage
{
    public function storeTemporaryUpload(UploadedFile $file): string
    {
        $directory = trim((string) config('price_indices.source_files.temp_directory'), '/');
        $filename = Str::uuid().'.upload';
        $path = $file->storeAs($directory, $filename, $this->diskName());

        if ($path === false) {
            throw new SourceFileStorageException();
        }

        return $path;
    }

    public function absolutePath(string $path): string
    {
        return $this->disk()->path($path);
    }

    public function exists(string $path): bool
    {
        return $this->disk()->exists($path);
    }

    public function size(string $path): int
    {
        return $this->disk()->size($path);
    }

    public function move(string $from, string $to): void
    {
        if (! $this->disk()->move($from, $to)) {
            throw new SourceFileStorageException();
        }
    }

    public function deleteIfExists(string $path): void
    {
        if ($path === '' || ! $this->disk()->exists($path)) {
            return;
        }

        if (! $this->disk()->delete($path)) {
            throw new SourceFileStorageException('Unable to clean up a source file.');
        }
    }

    /**
     * @param array<string, string> $headers
     */
    public function download(string $path, string $filename, array $headers): StreamedResponse
    {
        return $this->disk()->download($path, $filename, $headers);
    }

    protected function disk(): FilesystemAdapter
    {
        return Storage::disk($this->diskName());
    }

    protected function diskName(): string
    {
        return (string) config('price_indices.source_files.storage_disk', 'local');
    }
}
