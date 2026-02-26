<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceSingleSession
{
    /**
     * Проверяет, что текущая сессия соответствует current_session_id пользователя.
     * Если нет — принудительный logout + 401.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Пропускаем проверку сессии для token-based запросов (Chrome extension и т.д.)
        // У таких запросов нет session store — вызов $request->session() приведёт к ошибке.
        if (!$request->hasSession()) {
            return $next($request);
        }

        if ($user && $user->current_session_id) {
            $currentSessionId = $request->session()->getId();

            if ($currentSessionId !== $user->current_session_id) {
                // Сессия инвалидирована другим входом
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return response()->json([
                    'message' => 'Сеанс завершён: выполнен вход на другом устройстве.',
                    'reason' => 'session_terminated',
                ], 401);
            }
        }

        return $next($request);
    }
}
