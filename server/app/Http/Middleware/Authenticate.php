<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;

/**
 * Кастомный auth-middleware: не делает редирект на login, а даёт 401.
 * Это предотвращает ошибку "Route [login] not defined" для API-запросов.
 */
class Authenticate extends Middleware
{
    /**
     * Возвращаем null, чтобы всегда бросался AuthenticationException,
     * который далее конвертируется в JSON 401 в bootstrap/app.php.
     */
    protected function redirectTo($request): ?string
    {
        return null;
    }
}
