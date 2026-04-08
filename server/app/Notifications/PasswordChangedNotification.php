<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Security confirmation sent after any successful password change or password reset.
 *
 * Dispatch sites:
 *   - AuthController::updatePassword()   — PUT  /api/me/password
 *   - AuthController::changePassword()   — POST /api/auth/password/change
 *   - PasswordResetController::resetPassword() — POST /api/reset-password  ($isReset = true)
 *
 * Queued via the database queue driver.
 */
class PasswordChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param bool $isReset  True when triggered by a password-reset flow (token-based).
     *                       False when the user changed their own password while logged in.
     */
    public function __construct(private readonly bool $isReset = false) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendBase = rtrim((string) config('app.frontend_url', config('app.url', '')), '/');
        $resetUrl     = $frontendBase . '/forgot-password';

        return (new MailMessage)
            ->subject('Пароль изменён – PrismCore')
            ->view('emails.auth.password-changed', [
                'isReset'  => $this->isReset,
                'resetUrl' => $resetUrl,
            ]);
    }
}
