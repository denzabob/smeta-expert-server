<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Application\Support\PublicIndexSlug;
use Tests\TestCase;

class PublicIndexSlugTest extends TestCase
{
    public function test_numeric_and_rosstat_ag_codes_have_stable_slugs(): void
    {
        $slugs = app(PublicIndexSlug::class);

        $this->assertSame('31-02-10-140', $slugs->fromItemCode('31.02.10.140'));
        $this->assertSame('05-10-10-101-ag', $slugs->fromItemCode(" 05.10.10.101.аг\u{00A0}"));
    }

    public function test_unsafe_or_empty_codes_do_not_produce_public_slugs(): void
    {
        $slugs = app(PublicIndexSlug::class);

        $this->assertNull($slugs->fromItemCode(''));
        $this->assertNull($slugs->fromItemCode('31.02?draft=1'));
        $this->assertNull($slugs->fromItemCode('31/02%20'));
    }
}
