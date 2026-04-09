<?php

namespace App\Services\Auth;

use App\Models\User;

/**
 * Canonical auth-method profile.
 *
 * Centralises the derivation of which auth factors a user has linked,
 * which are verified, and what recommended/completion actions remain.
 * All controllers and API responses should use this service instead of
 * computing auth-method state inline.
 */
class AuthMethodProfileService
{
    public function __construct(private readonly LoginMethodService $loginMethodService) {}

    /**
     * Return the full structured auth-method profile for a user.
     * Safe to expose in API responses — no raw secrets.
     */
    public function profile(User $user): array
    {
        $hasPassword   = $this->hasPassword($user);
        $hasPhone      = !empty($user->phone);
        $phoneVerified = $hasPhone && $user->phone_verified_at !== null;
        $hasEmail      = !empty($user->email);
        $emailVerified = $hasEmail && $user->email_verified_at !== null;
        $hasYandex     = $this->hasYandexLink($user);
        $pinEnabled    = (bool) $user->pin_enabled;
        $deviceCount   = $user->activeTrustedDevices()->count();

        // canStepUp: any factor that can currently verify identity.
        // Email OTP is reliable (SMTP configured). Phone OTP is only operational
        // when a transport (Telegram Gateway / SMS.ru) is available or test mode is on.
        $phoneStepUpEnabled = $this->isPhoneStepUpEnabled();
        $canStepUp = $hasPassword || $emailVerified || ($phoneVerified && $phoneStepUpEnabled);

        $recoveryMethods = $this->recoveryMethods($user, $hasPassword, $phoneVerified, $hasYandex);
        $blockedActions  = $this->blockedActions($hasPassword, $pinEnabled, $canStepUp);

        return [
            'phone' => [
                'linked'   => $hasPhone,
                'verified' => $phoneVerified,
                'masked'   => $hasPhone ? VerificationCodeService::maskPhone($user->phone) : null,
            ],
            'email' => [
                'linked'   => $hasEmail,
                'verified' => $emailVerified,
                'masked'   => $hasEmail ? $this->maskEmail($user->email) : null,
            ],
            'password' => [
                'set' => $hasPassword,
            ],
            'yandex' => [
                'linked' => $hasYandex,
            ],
            'quick_pin' => [
                'enabled' => $pinEnabled,
            ],
            'trusted_devices' => [
                'count' => $deviceCount,
            ],
            'recommended_actions' => $this->recommendedActions(
                $user, $hasPassword, $phoneVerified, $emailVerified, $pinEnabled, $hasYandex, $canStepUp
            ),
            'completion' => [
                'needs_email'               => !$hasEmail,
                'needs_email_verification'  => $hasEmail && !$emailVerified,
                'needs_password_setup'      => !$hasPassword,
                'can_enable_quick_pin'      => $canStepUp,
            ],
            // ── Recovery & control surface ──────────────────────────────
            'can_self_recover'           => !empty($recoveryMethods),
            'recovery_methods'           => $recoveryMethods,
            'can_manage_sessions'        => true,
            'can_manage_trusted_devices' => true,
            'blocked_actions'            => $blockedActions,
            'prerequisite_actions'       => $this->prerequisiteActions($blockedActions, $hasYandex, $phoneVerified, $hasEmail, $emailVerified),
            // ── Step-up availability (Block 6A) ─────────────────────────
            'available_step_up_methods'  => $this->availableStepUpMethods($hasPassword, $phoneVerified, $emailVerified, $phoneStepUpEnabled),
        ];
    }

    // ─── Per-field helpers (also used by StepUpService) ─────────────────────

    public function hasPassword(User $user): bool
    {
        return !empty($user->password);
    }

    public function hasVerifiedPhone(User $user): bool
    {
        return !empty($user->phone) && $user->phone_verified_at !== null;
    }

    public function hasVerifiedEmail(User $user): bool
    {
        return !empty($user->email) && $user->email_verified_at !== null;
    }

    public function hasYandexLink(User $user): bool
    {
        return $user->socialAccounts()
            ->where('provider', 'yandex')
            ->where('is_active', true)
            ->exists();
    }

    // ─── Recommended actions (actionable-only) ───────────────────────────────

    /**
     * Deterministic ordered list of recommended completion actions.
     * Ordered by security priority.
     * Only includes actions the user can actually complete given their current state.
     */
    private function recommendedActions(
        User $user,
        bool $hasPassword,
        bool $phoneVerified,
        bool $emailVerified,
        bool $pinEnabled,
        bool $hasYandex,
        bool $canStepUp
    ): array {
        $actions = [];

        // Phase 1: Email
        if (!$emailVerified) {
            $actions[] = empty($user->email) ? 'add_email' : 'verify_email';
        }

        // Phase 2: Phone
        if (!$phoneVerified) {
            if ($hasYandex && !$hasPassword) {
                // Yandex-only bootstrap trap: recommend the safe escape path
                $actions[] = 'bootstrap_add_phone';
            } elseif (empty($user->phone)) {
                // Has password but no phone — add phone for recovery diversity
                // Only recommend this if user already has at least one factor
                if ($hasPassword) {
                    $actions[] = 'add_phone';
                }
            } elseif (!empty($user->phone)) {
                // Has phone but not verified
                $actions[] = 'verify_phone';
            }
        }

        // Phase 3: Password — only if a real step-up factor exists.
        // canStepUp at this point already reflects email + (phone if operational).
        if (!$hasPassword && $canStepUp) {
            $actions[] = 'set_password';
        }
        // Do NOT recommend set_password when !$canStepUp — it's blocked

        // Phase 4: PIN — only if actionable
        if (!$pinEnabled && $canStepUp) {
            $actions[] = 'enable_quick_pin';
        }

        return $actions;
    }

    // ─── Recovery methods ─────────────────────────────────────────────────────

    /**
     * Determine which recovery paths are available to this user.
     * These are methods by which the user can regain account access.
     */
    private function recoveryMethods(
        User $user,
        bool $hasPassword,
        bool $phoneVerified,
        bool $hasYandex
    ): array {
        $methods = [];

        if ($phoneVerified) {
            $methods[] = 'phone_otp';
        }

        // Password reset requires the password flow + a reachable email
        if ($hasPassword && !empty($user->email)) {
            $methods[] = 'password_reset';
        }

        if ($hasYandex) {
            $methods[] = 'yandex_oauth';
        }

        return $methods;
    }

    // ─── Blocked actions ─────────────────────────────────────────────────────

    /**
     * Actions that are semantically available but cannot currently be completed
     * because a prerequisite (step-up factor) is missing.
     */
    private function blockedActions(bool $hasPassword, bool $pinEnabled, bool $canStepUp): array
    {
        $blocked = [];

        if (!$hasPassword && !$canStepUp) {
            $blocked[] = 'set_password';
        }

        if (!$pinEnabled && !$canStepUp) {
            $blocked[] = 'enable_quick_pin';
        }

        return $blocked;
    }

    // ─── Prerequisite actions ─────────────────────────────────────────────────

    /**
     * For each blocked action, what the user should do first to unblock it.
     * Returns a map of blocked_action => next_step_action.
     */
    private function prerequisiteActions(
        array $blockedActions,
        bool $hasYandex,
        bool $phoneVerified,
        bool $hasEmail = false,
        bool $emailVerified = false
    ): array {
        if (empty($blockedActions)) {
            return [];
        }

        $prerequisites = [];

        foreach ($blockedActions as $action) {
            if (in_array($action, ['set_password', 'enable_quick_pin'], true)) {
                if ($hasYandex && !$phoneVerified && !$emailVerified) {
                    // Yandex-only bootstrap trap with no verified email: must add phone first
                    $prerequisites[$action] = 'bootstrap_add_phone';
                } elseif ($hasEmail && !$emailVerified) {
                    // Email linked but not verified — verify it to unlock email OTP step-up
                    $prerequisites[$action] = 'verify_email';
                } elseif (!$hasEmail) {
                    // No email at all — add email first (email OTP is the reliable path)
                    $prerequisites[$action] = 'add_email';
                } else {
                    // Has verified email but still blocked? Fallback to phone.
                    $prerequisites[$action] = 'verify_phone';
                }
            }
        }

        return $prerequisites;
    }

    // ─── Step-up method availability ─────────────────────────────────────────

    /**
     * Return the step-up methods that are currently available to this user.
     * Phone OTP is included only when a transport is operational (or test mode).
     */
    private function availableStepUpMethods(
        bool $hasPassword,
        bool $phoneVerified,
        bool $emailVerified,
        bool $phoneStepUpEnabled
    ): array {
        $methods = [];
        if ($hasPassword)    $methods[] = 'password';
        if ($emailVerified)  $methods[] = 'email_otp';
        if ($phoneVerified && $phoneStepUpEnabled) $methods[] = 'phone_otp';
        return $methods;
    }

    /**
     * True when at least one phone OTP transport is available or test mode is on.
     * Used to avoid recommending password setup when the only step-up path
     * (phone OTP) does not currently have working delivery.
     */
    private function isPhoneStepUpEnabled(): bool
    {
        return (bool) config('verification.test_mode', false)
            || (bool) config('verification.telegram_gateway.enabled', false)
            || (bool) config('verification.sms_ru.enabled', false);
    }

    // ─── Internal masking ────────────────────────────────────────────────────

    public function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);

        $visibleChars = min(1, strlen($local));
        $masked = substr($local, 0, $visibleChars) . str_repeat('*', max(0, strlen($local) - $visibleChars));

        return $masked . '@' . $domain;
    }
}
