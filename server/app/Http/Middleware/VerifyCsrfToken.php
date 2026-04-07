<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * URIs excluded from CSRF verification.
     *
     * Each exclusion must have a documented justification.
     *
     * REMOVED: 'api/chrome/*' — Chrome extension routes use Sanctum Bearer-token
     *   auth, not session cookies; CSRF protection does not apply and the broad
     *   wildcard was unnecessarily exposing all chrome endpoints.
     *   Rate limiting is applied to api/chrome/auth/token in routes/api.php instead.
     *
     * @var array<int, string>
     */
    protected $except = [
        // Parser service calls /api/materials/fetch without a browser session.
        // Protected separately via InternalOnlyMiddleware on the relevant routes.
        'api/materials/fetch',

        // External webhook from sms.ru CallCheck — cannot carry a CSRF token.
        'api/auth/phone/callcheck/webhook',
        'api/auth/phone/call/webhook',
    ];
}
