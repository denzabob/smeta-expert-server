<?php

namespace App\Domain\PriceIndices\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePriceIndicesAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('price_indices.enabled') !== true) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $role = $request->user()?->role;

        if (! in_array($role, ['admin', 'superadmin'], true)) {
            abort(Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
