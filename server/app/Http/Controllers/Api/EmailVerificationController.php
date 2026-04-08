<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use App\Services\AuthAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class EmailVerificationController extends Controller
{
    public function __construct(private readonly AuthAuditService $audit) {}

    /**
     * POST /api/email/verification-notification
     *
     * Send (or re-send) a verification email to the authenticated user.
     * Rate-limited: 3 sends per 5 minutes per user.
     */
    public function sendNotification(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->email) {
            return response()->json([
                'message' => 'Email не указан. Заполните профиль.',
            ], 422);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Email уже подтверждён.',
            ]);
        }

        $rateLimitKey = 'email_verify_auth_resend:' . $user->id;
        if (RateLimiter::tooManyAttempts($rateLimitKey, 3)) {
            return response()->json([
                'message'     => 'Слишком много запросов. Попробуйте позже.',
                'retry_after' => RateLimiter::availableIn($rateLimitKey),
            ], 429);
        }
        RateLimiter::hit($rateLimitKey, 300); // 5-minute window

        $user->notify(new VerifyEmailNotification());
        $this->audit->verificationResent($user->id, $request);

        return response()->json([
            'message' => 'Ссылка для подтверждения отправлена.',
        ]);
    }

    /**
     * POST /api/email/resend-verification
     *
     * Public (unauthenticated) resend endpoint for users who registered but
     * cannot yet log in (unverified state). Rate-limited per email hash.
     * Anti-enumeration: always returns the same 200 response.
     */
    public function resendVerification(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $email = mb_strtolower(trim((string) $request->input('email')));

        // Rate limit per email hash: 3 sends per 15 minutes.
        // Applied regardless of whether the user exists (anti-abuse).
        $rateLimitKey = 'email_verify_public_resend:' . hash('sha256', $email);

        if (!RateLimiter::tooManyAttempts($rateLimitKey, 3)) {
            RateLimiter::hit($rateLimitKey, 900); // 15-minute window

            $user = User::whereRaw('LOWER(email) = ?', [$email])
                ->whereNull('email_verified_at')
                ->first();

            if ($user) {
                $user->notify(new VerifyEmailNotification());
                $this->audit->verificationResent($user->id, $request, ['trigger' => 'public_resend']);
            }
        }

        // Always the same response — never disclose whether email is registered.
        return response()->json([
            'message' => 'Если такой email зарегистрирован и не подтверждён, мы отправили письмо.',
        ]);
    }

    /**
     * GET /api/email/verify/{id}/{hash}
     *
     * Verify the user's email address via signed URL from email.
     */
    public function verify(Request $request, int $id, string $hash): RedirectResponse
    {
        $frontendBase = $this->resolveFrontendBase($request);

        $user = User::find($id);

        if (!$user) {
            return redirect($frontendBase . '/login?error=email_verify_invalid');
        }

        // Verify the hash matches
        if (!hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return redirect($frontendBase . '/login?error=email_verify_invalid');
        }

        if ($user->hasVerifiedEmail()) {
            return redirect($frontendBase . '/settings?email_verified=already');
        }

        $user->markEmailAsVerified();
        $this->audit->emailVerified($user->id, $request);

        return redirect($frontendBase . '/settings?email_verified=success');
    }

    protected function resolveFrontendBase(Request $request): string
    {
        $configured = (string) config('app.frontend_url', '');
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return rtrim($request->getSchemeAndHttpHost(), '/');
    }
}
