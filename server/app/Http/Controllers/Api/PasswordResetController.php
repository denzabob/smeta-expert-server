<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use App\Notifications\VerifyEmailNotification;
use App\Services\AuthAuditService;
use App\Services\AuthMailService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class PasswordResetController extends Controller
{
    public function __construct(
        private readonly AuthAuditService $audit,
        private readonly AuthMailService  $mail,
    ) {}

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

        $email = mb_strtolower(trim((string) $request->input('email')));
        $frontendBase = $this->resolveFrontendBase($request);

        // If the account is found but not yet verified, send a verification email
        // instead of a password reset link.  The user should prove email ownership
        // (via verification) before they can reset the password.
        // Anti-enumeration is maintained: the response is always identical.
        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

        if ($user && !$user->hasVerifiedEmail()) {
            $user->notify(new VerifyEmailNotification());
            $this->audit->verificationResent($user->id, $request, ['trigger' => 'forgot_password_unverified']);
        } else {
            // Verified or non-existent: standard reset link flow via AuthMailService.
            $this->mail->sendPasswordResetLink($email, $frontendBase);
        }

        // Log at the same point regardless of branch (anti-enumeration).
        $this->audit->passwordResetRequested($request);

        return response()->json([
            'message' => 'Если указанный email зарегистрирован, мы отправили ссылку для сброса пароля.',
        ]);
    }

    /**
     * POST /api/reset-password
     *
     * Reset the user's password using a token.
     * On success, revokes ALL sessions, Sanctum tokens, and trusted devices.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token'    => ['required', 'string'],
            'email'    => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) use ($request) {
                $user->forceFill([
                    'password'       => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                // Revoke ALL sessions — the reset is external, no current session to preserve.
                DB::table('sessions')->where('user_id', $user->id)->delete();
                $user->update(['current_session_id' => null]);

                // Revoke ALL Sanctum tokens (chrome extension tokens).
                $tokenCount = $user->tokens()->count();
                $user->tokens()->delete();

                // Revoke ALL trusted devices — password is compromised so all trust is reset.
                $deviceCount = $user->activeTrustedDevices()->count();
                $user->activeTrustedDevices()->update(['revoked_at' => now()]);

                event(new PasswordReset($user));

                // Notify the account owner that their password was reset.
                // Sent regardless of who initiated it — acts as a security confirmation.
                if ($user->email) {
                    $user->notify(new PasswordChangedNotification(isReset: true));
                }

                $this->audit->passwordResetCompleted($user->id, $request, [
                    'sessions_revoked' => true,
                    'tokens_revoked'   => $tokenCount,
                    'devices_revoked'  => $deviceCount,
                ]);
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
            Password::INVALID_TOKEN  => 'Недействительный или истекший токен сброса.',
            Password::INVALID_USER   => 'Пользователь с таким email не найден.',
            Password::RESET_THROTTLED => 'Подождите перед повторной попыткой.',
            default                  => 'Не удалось сбросить пароль.',
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

