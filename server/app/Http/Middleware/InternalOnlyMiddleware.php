<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

class InternalOnlyMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $clientIp = $request->getClientIp();
        $allowedIps = config('parser.allowed_ips', ['127.0.0.1', '::1']);

        // Allow через переменную окружения для override'а в production
        if ($envAllowed = env('PARSER_CALLBACK_IPS')) {
            $allowedIps = array_map('trim', explode(',', $envAllowed));
        }

        // Проверяем если в production mode - требуем HTTPS
        if (!app()->isLocal()) {
            if ($request->getScheme() !== 'https') {
                Log::warning('Parser callback attempted over non-HTTPS', [
                    'ip' => $clientIp,
                    'path' => $request->path(),
                ]);

                return response()->json([
                    'error' => 'HTTPS required',
                    'message' => 'Parser callbacks must be sent over HTTPS',
                ], Response::HTTP_FORBIDDEN);
            }
        }

        // Проверяем IP
        $ipAllowed = in_array($clientIp, $allowedIps);
        
        // В локальном окружении разрешаем все IP из Docker подсети
        if (app()->isLocal() && !$ipAllowed) {
            // Проверяем IP из Docker сети (172.18.0.0/16)
            $ipParts = explode('.', $clientIp);
            if (count($ipParts) === 4 && $ipParts[0] === '172' && $ipParts[1] === '18') {
                $ipAllowed = true;
            }
        }
        
        if (!$ipAllowed) {
            Log::warning('Parser callback attempted from unauthorized IP', [
                'ip' => $clientIp,
                'allowed' => $allowedIps,
                'path' => $request->path(),
            ]);

            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'This endpoint is restricted to internal requests only',
            ], Response::HTTP_FORBIDDEN);
        }

        // Проверяем token если он задан
        if ($token = config('parser.callback_token')) {
            $providedToken = $request->header('X-Parser-Token');
            $bearer = $request->bearerToken();
            $candidate = $providedToken ?: $bearer;

            if (!hash_equals($token, $candidate ?? '')) {
                Log::warning('Parser callback attempted with invalid token', [
                    'ip' => $clientIp,
                ]);

                return response()->json([
                    'error' => 'Unauthorized',
                    'message' => 'Invalid or missing callback token',
                ], Response::HTTP_UNAUTHORIZED);
            }
        }

        return $next($request);
    }
}
