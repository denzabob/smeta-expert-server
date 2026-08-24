<?php

namespace App\Domain\PriceIndices\Infrastructure\Http;

use App\Domain\PriceIndices\Application\Data\ClassifierHttpResponse;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierAcquisitionException;
use DateTimeImmutable;
use DateTimeZone;

class ClassifierResponseMetadata
{
    public function location(ClassifierHttpResponse $response): string
    {
        return $this->singleHeader($response, 'Location', 2_048, true) ?? '';
    }

    public function mimeType(ClassifierHttpResponse $response): string
    {
        $contentType = $this->singleHeader($response, 'Content-Type', 255, true) ?? '';
        $mimeType = strtolower(trim(explode(';', $contentType, 2)[0]));

        if ($mimeType === '' || preg_match('~^[a-z0-9!#$&^_.+\-]+/[a-z0-9!#$&^_.+\-]+$~', $mimeType) !== 1) {
            $this->reject('Content-Type header is invalid.');
        }

        return $mimeType;
    }

    public function contentLength(ClassifierHttpResponse $response): ?int
    {
        $value = $this->singleHeader($response, 'Content-Length', 32, false);

        if ($value === null) {
            return null;
        }

        if (preg_match('/^(0|[1-9][0-9]*)$/', $value) !== 1) {
            $this->reject('Content-Length header is invalid.');
        }

        $length = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        if (! is_int($length)) {
            $this->reject('Content-Length header is outside the supported range.');
        }

        return $length;
    }

    public function etag(ClassifierHttpResponse $response): ?string
    {
        return $this->singleHeader($response, 'ETag', 512, false);
    }

    public function lastModified(ClassifierHttpResponse $response): ?DateTimeImmutable
    {
        $value = $this->singleHeader($response, 'Last-Modified', 64, false);

        if ($value === null) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat(
            'D, d M Y H:i:s \G\M\T',
            $value,
            new DateTimeZone('UTC'),
        );

        if ($date === false || $date->format('D, d M Y H:i:s \G\M\T') !== $value) {
            $this->reject('Last-Modified header is invalid.');
        }

        return $date;
    }

    private function singleHeader(
        ClassifierHttpResponse $response,
        string $name,
        int $maxLength,
        bool $required,
    ): ?string {
        $values = $response->header($name);

        if ($values === []) {
            if ($required) {
                $this->reject("{$name} header is required.");
            }

            return null;
        }

        if (count($values) !== 1) {
            $this->reject("{$name} header must occur exactly once.");
        }

        $value = trim($values[0]);

        if ($value === '' || strlen($value) > $maxLength || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            $this->reject("{$name} header is invalid.");
        }

        return $value;
    }

    private function reject(string $message): never
    {
        throw new ClassifierAcquisitionException('invalid_response_metadata', $message);
    }
}
