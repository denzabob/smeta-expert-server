<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class PasswordResetController extends Controller
{
    /**
     * POST /api/forgot-password
     *
     * Send a password reset link.
     * Anti-enumeration: always returns 200 with identical message.
     */
    public function sendResetLink(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        // Configure reset URL to point to SPA frontend
        $frontendBase = $this->resolveFrontendBase($request);
        ResetPassword::createUrlUsing(function ($notifiable, string $token) use ($frontendBase) {
            return $frontendBase . '/reset-password?token=' . $token . '&email=' . urlencode($notifiable->getEmailForPasswordReset());
        });

        // Send the reset link (silently ignores non-existent emails internally)
        Password::sendResetLink($request->only('email'));

        // Anti-enumeration: always same response
        return response()->json([
            'message' => 'Если указанный email зарегистрирован, мы отправили ссылку для сброса пароля.',
        ]);
    }

    /**
     * POST /api/reset-password
     *
     * Reset the user's password using a token.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'Пароль успешно изменён.',
            ]);
        }

        return response()->json([
            'message' => $this->translateStatus($status),
        ], 422);
    }

    protected function translateStatus(string $status): string
    {
        return match ($status) {
            Password::INVALID_TOKEN => 'Недействительный или истекший токен сброса.',
            Password::INVALID_USER => 'Пользователь с таким email не найден.',
            Password::RESET_THROTTLED => 'Подождите перед повторной попыткой.',
            default => 'Не удалось сбросить пароль.',
        };
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
