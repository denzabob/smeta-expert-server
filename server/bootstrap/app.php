<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;  // ← Обязательно импортируйте
use Illuminate\Http\Request;                  // ← Для типа Request
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\EnforceSingleSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->statefulApi();  // ← Должно быть уже добавлено ранее

        // Используем кастомный auth-мидлвар, чтобы API не пытался редиректить на login.
        $middleware->alias([
            'auth' => Authenticate::class,
            'single_session' => EnforceSingleSession::class,
        ]);

        // Добавляем EnforceSingleSession ко всем API-запросам после auth:sanctum
        $middleware->appendToGroup('api', [
            EnforceSingleSession::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => 'Unauthenticated.'
                ], 401);
            }

            // Опционально: для web-роутов редирект на login, если у вас есть web-часть
            // return redirect()->guest(route('login'));
        });
    })->create();
