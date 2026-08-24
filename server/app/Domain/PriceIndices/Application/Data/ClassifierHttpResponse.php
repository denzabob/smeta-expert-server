<?php

namespace App\Domain\PriceIndices\Application\Data;

use Psr\Http\Message\StreamInterface;

final readonly class ClassifierHttpResponse
{
    /** @param array<string, list<string>> $headers */
    public function __construct(
        public int $status,
        public array $headers,
        public StreamInterface $body,
    ) {}

    /** @return list<string> */
    public function header(string $name): array
    {
        $expected = strtolower($name);

        foreach ($this->headers as $header => $values) {
            if (strtolower($header) === $expected) {
                return $values;
            }
        }

        return [];
    }

    public function close(): void
    {
        $this->body->close();
    }
}
