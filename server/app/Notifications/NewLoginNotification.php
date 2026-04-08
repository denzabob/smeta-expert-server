<?php

namespace App\Notifications;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Security alert sent on every login from an unrecognized browser / session context.
 *
 * Triggered in AuthController::login() when no trusted-device cookie is present.
 * This is notification-only — it does NOT block the login or require approval.
 *
 * Safe metadata included: approximate time (UTC), IP, simplified browser name.
 * No tokens, passwords, or session IDs are ever included.
 *
 * Queued via the database queue driver.
 */
class NewLoginNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string            $ip,
        private readonly string            $userAgent,
        private readonly DateTimeInterface $loginAt,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendBase = rtrim((string) config('app.frontend_url', config('app.url', '')), '/');
        $resetUrl     = $frontendBase . '/forgot-password';

        return (new MailMessage)
            ->subject('Вход в аккаунт – Призма')
            ->view('emails.auth.new-login', [
                'time'     => $this->loginAt->format('d.m.Y H:i') . ' UTC',
                'ip'       => $this->ip,
                'device'   => $this->describeUserAgent($this->userAgent),
                'resetUrl' => $resetUrl,
            ]);
    }

    /**
     * Convert a raw User-Agent string into a human-readable browser name.
     * We never include the full UA in emails to keep content safe and concise.
     */
    private function describeUserAgent(string $ua): string
    {
        if ($ua === '' || $ua === 'unknown') {
            return 'Неизвестный';
        }
        if (str_contains($ua, 'Edg/') || str_contains($ua, 'EdgA/')) {
            return 'Microsoft Edge';
        }
        if (str_contains($ua, 'OPR/') || str_contains($ua, 'Opera/')) {
            return 'Opera';
        }
        if (str_contains($ua, 'Chrome/') && !str_contains($ua, 'Chromium')) {
            return 'Google Chrome';
        }
        if (str_contains($ua, 'Firefox/')) {
            return 'Mozilla Firefox';
        }
        if (str_contains($ua, 'Safari/') && !str_contains($ua, 'Chrome')) {
            return 'Safari';
        }
        // Unknown UA: truncate to 80 chars, do not expose full header
        return mb_strimwidth($ua, 0, 80, '…');
    }
}
