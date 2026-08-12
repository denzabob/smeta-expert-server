<?php

namespace Tests\Feature\PriceIndices\Support;

use Illuminate\Testing\TestResponse;
use JsonException;

trait ParsesPublicStructuredData
{
    /** @return array<string, mixed> */
    private function structuredData(TestResponse $response): array
    {
        preg_match_all(
            '/<script type="application\/ld\+json">(.*?)<\/script>/s',
            $response->getContent(),
            $matches,
        );
        $this->assertCount(1, $matches[1], 'Expected exactly one JSON-LD block.');

        try {
            $decoded = json_decode($matches[1][0], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->fail('JSON-LD is invalid: '.$exception->getMessage());
        }

        return $decoded;
    }

    /** @return array<string, mixed> */
    private function graphEntity(array $structuredData, string $type): array
    {
        foreach ($structuredData['@graph'] ?? [] as $entity) {
            if (($entity['@type'] ?? null) === $type) {
                return $entity;
            }
        }

        $this->fail("Schema.org entity {$type} was not found.");
    }
}
