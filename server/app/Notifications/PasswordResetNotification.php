<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Password reset notification.
 *
 * Replaces Laravel's default Illuminate\Auth\Notifications\ResetPassword.
 * Dispatch path: Password::sendResetLink() → User::sendPasswordResetNotification($token)
 *                → $this->notify(new PasswordResetNotification($token))
 *
 * Queued via the database queue driver.
 * URL is built from config('app.frontend_url') so it always points to the SPA.
 */
class PasswordResetNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = $this->resetUrl($notifiable);

        return (new MailMessage)
            ->subject('Сброс пароля – PrismCore')
            ->view('emails.auth.reset-password', ['url' => $url]);
    }

    protected function resetUrl(object $notifiable): string
    {
        $frontendBase = rtrim((string) config('app.frontend_url', config('app.url', '')), '/');

        return $frontendBase . '/reset-password'
             . '?token=' . $this->token
             . '&email=' . urlencode((string) $notifiable->getEmailForPasswordReset());
    }
}
