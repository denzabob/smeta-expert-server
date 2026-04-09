<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Step-up email OTP notification (Block 6A).
 *
 * Sends a short-lived numeric code to the user's verified email address
 * as part of the email OTP step-up flow. Used to authorize sensitive
 * actions like setting a password when no working phone OTP path exists.
 *
 * Security:
 *  - Code is NOT included in app logs or audit events (only a hash is stored).
 *  - Notification is queued.
 *  - Code is valid for 10 minutes (controlled by StepUpService / AuthVerificationChallenge TTL).
 */
class StepUpEmailOtpNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $code) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Код подтверждения – Призма')
            ->view('emails.auth.step-up-otp', ['code' => $this->code]);
    }
}
