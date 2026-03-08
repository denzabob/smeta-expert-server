<?php

namespace Tests\Unit\Services;

use App\Services\UrlNormalizer;
use PHPUnit\Framework\TestCase;

class UrlNormalizerTest extends TestCase
{
    public function test_removes_tracking_params_and_fragment(): void
    {
        $service = new UrlNormalizer();

        $url = 'https://example.com/catalog/item?utm_source=ads&gclid=abc&id=42#details';
        $normalized = $service->normalize($url);

        $this->assertSame('https://example.com/catalog/item?id=42', $normalized);
    }

    public function test_keeps_non_tracking_query_params(): void
    {
        $service = new UrlNormalizer();

        $url = 'https://example.com/x?color=white&size=16&utm_campaign=test';
        $normalized = $service->normalize($url);

        $this->assertSame('https://example.com/x?color=white&size=16', $normalized);
    }
}
