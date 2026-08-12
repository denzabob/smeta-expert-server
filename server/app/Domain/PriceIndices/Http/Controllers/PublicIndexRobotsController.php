<?php

namespace App\Domain\PriceIndices\Http\Controllers;

use App\Domain\PriceIndices\Application\Support\PublicPriceIndexUrl;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

final class PublicIndexRobotsController extends Controller
{
    public function __invoke(PublicPriceIndexUrl $urls): Response
    {
        return response(
            "User-agent: *\nAllow: /\n\nSitemap: {$urls->sitemap()}\n",
            200,
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }
}
