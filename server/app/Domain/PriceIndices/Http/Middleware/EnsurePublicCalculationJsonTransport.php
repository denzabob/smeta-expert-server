<?php

namespace App\Domain\PriceIndices\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePublicCalculationJsonTransport
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->wantsJson()) {
            return $next($request);
        }

        $response = redirect()->route('price-indices.public.detail', [
            'slug' => (string) $request->route('slug'),
        ], 303);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
