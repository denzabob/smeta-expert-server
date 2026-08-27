<?php

namespace App\Domain\PriceIndices\Http\Middleware;

use App\Domain\PriceIndices\Application\Services\PublicIndexFamilyRegistry;
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

        $family = (string) $request->route('family');
        $routeName = $family === PublicIndexFamilyRegistry::CONSUMER_PRICES
            ? 'price-indices.public.consumer-prices.detail'
            : 'price-indices.public.detail';
        $response = redirect()->route($routeName, [
            'slug' => (string) $request->route('slug'),
        ], 303);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
