<?php

namespace App\Domain\PriceIndices\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CachePublicIndexResponse
{
    private const CACHE_CONTROL = 'public, max-age=300, s-maxage=600, stale-while-revalidate=60';

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);
        $contentType = (string) $response->headers->get('Content-Type');

        if (in_array($request->getMethod(), ['GET', 'HEAD'], true)
            && $response->getStatusCode() === Response::HTTP_OK
            && $response->headers->getCookies() === []
            && preg_match('#^(text/html|text/plain|application/xml)(?:;|$)#i', $contentType) === 1
        ) {
            $response->headers->set('Cache-Control', self::CACHE_CONTROL);
        }

        return $response;
    }
}
