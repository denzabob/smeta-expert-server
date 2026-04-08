<?php

namespace App\Services;

use Illuminate\Support\Facades\Password;

/**
 * Centralized dispatch for auth-related email flows.
 *
 * Password reset: delegates to Password::sendResetLink() which calls
 * User::sendPasswordResetNotification($token) → PasswordResetNotification.
 * That notification is queued, branded in Russian, and uses the SPA reset URL.
 *
 * Anti-enumeration: always returns true regardless of whether the email
 * is registered — callers must not reveal that distinction to users.
 */
class AuthMailService
{
    /**
     * Trigger a password reset email for the given address.
     *
     * The URL and branding are handled inside PasswordResetNotification.
     * The $frontendBase parameter is kept for backward compatibility.
     */
    public function sendPasswordResetLink(string $email, string $frontendBase): bool
    {
        // Password::sendResetLink() finds the user and calls
        // User::sendPasswordResetNotification($token) which dispatches
        // App\Notifications\PasswordResetNotification (queued, branded).
        Password::sendResetLink(['email' => $email]);

        return true;
    }
}
