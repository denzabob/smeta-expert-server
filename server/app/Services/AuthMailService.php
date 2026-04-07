<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Password;

/**
 * Centralized mail dispatch for auth-related emails.
 *
 * Currently wraps Laravel's built-in password-reset notification.
 * Extend this class in later blocks to add:
 *   - email verification
 *   - login-from-new-device alerts
 *   - account-locked notices
 *
 * All sending goes through the configured MAIL_MAILER (see .env / config/mail.php).
 * Set MAIL_MAILER=smtp + real credentials for production delivery.
 * Set MAIL_MAILER=log for local development (emails appear in storage/logs/laravel.log).
 */
class AuthMailService
{
    /**
     * Send a password reset link to the given email address.
     *
     * Uses Laravel's Password broker so the token is managed and throttled
     * by the framework. The reset URL is built from FRONTEND_URL env var.
     *
     * Anti-enumeration: always returns true regardless of whether the email
     * is registered — callers must not reveal that distinction to users.
     */
    public function sendPasswordResetLink(string $email, string $frontendBase): bool
    {
        ResetPassword::createUrlUsing(function ($notifiable, string $token) use ($frontendBase) {
            return rtrim($frontendBase, '/') . '/reset-password?token=' . $token
                . '&email=' . urlencode($notifiable->getEmailForPasswordReset());
        });

        $status = Password::sendResetLink(['email' => $email]);

        // Returns true even for non-existent emails (anti-enumeration).
        return true;
    }
}
