<?php

namespace App\Domain\PriceIndices\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePriceIndicesUserAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('price_indices.enabled') !== true) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $role = $request->user()?->role;
        $isAdministrator = in_array($role, ['admin', 'superadmin'], true);

        if (! $isAdministrator && config('price_indices.admin_only') === true) {
            abort(Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
