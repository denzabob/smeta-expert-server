<?php

namespace App\Domain\PriceIndices\Http\Middleware;

use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PublicToolsJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            return $next($request);
        } catch (ThrottleRequestsException) {
            return response()->json([
                'error' => [
                    'code' => 'RATE_LIMITED',
                    'message' => 'Слишком много запросов. Попробуйте ещё раз позже.',
                ],
            ], 429, ['Cache-Control' => 'no-store']);
        }
    }
}
