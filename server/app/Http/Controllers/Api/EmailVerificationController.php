<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    /**
     * POST /api/email/verification-notification
     *
     * Send (or re-send) a verification email to the authenticated user.
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

        $user->notify(new VerifyEmailNotification());

        return response()->json([
            'message' => 'Ссылка для подтверждения отправлена.',
        ]);
    }

    /**
     * GET /api/email/verify/{id}/{hash}
     *
     * Verify the user's email address via signed URL from email.
     */
    public function verify(Request $request, int $id, string $hash): RedirectResponse
    {
        $frontendBase = config('app.frontend_url', 'http://localhost:5173');

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

        return redirect($frontendBase . '/settings?email_verified=success');
    }
}
